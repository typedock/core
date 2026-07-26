<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

use TypeDock\Core\PluginContext;
use TypeDock\Middleware\CsrfMiddleware;

/**
 * Public endpoint receiving Form component submissions. Runs entirely through
 * PluginContext utilities (db, mail, log) — a Hubspot-style plugin replacing
 * this flow would build the exact same way.
 */
class FormSubmitController
{
    public function __construct(private readonly PluginContext $ctx) {}

    public function submit(): void
    {
        // Anonymous submissions are guarded by honeypot + rate limit + captcha
        // rather than a token; see FormCsrf for the reasoning.
        if (FormCsrf::required()) {
            (new CsrfMiddleware())->verifyOrFail();
        }

        $formId = trim((string) ($_POST['form_id'] ?? ''));
        $back   = $this->sanitiseReturnPath((string) ($_POST['return_to'] ?? '/'));

        $service = new FormService($this->ctx->db()->pdo());
        $form    = $formId !== '' ? $service->find($formId) : null;

        if ($form === null) {
            $this->flash($formId, 'error', 'Form not found.', [], $_POST);
            $this->redirect($back);
            return;
        }

        $antispam = new FormAntispam($this->ctx->db()->pdo());
        $check    = $antispam->check($_POST, 'form:' . $formId);
        if (!$check['ok']) {
            $this->ctx->log()->warning('Form submission blocked', [
                'form_id' => $formId,
                'reason'  => $check['reason'] ?? '',
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            $this->flash(
                $formId,
                'error',
                $check['reason'] === 'rate_limited'
                    ? 'Too many submissions — please try again later.'
                    : 'Submission rejected.',
                [],
                $_POST
            );
            $this->redirect($back);
            return;
        }

        $captcha = $this->ctx->captcha()->verify($_POST, 'form_submit', [
            'form_id'    => $formId,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        if (!$captcha->ok) {
            $this->ctx->log()->warning('Form submission blocked', [
                'form_id' => $formId,
                'reason'  => 'captcha',
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            $this->flash($formId, 'error', $captcha->error ?? 'Captcha verification failed.', [], $_POST);
            $this->redirect($back);
            return;
        }

        $result = $service->submit($form, $_POST);
        if (!$result['ok']) {
            $this->flash($formId, 'error', 'Please correct the errors below.', $result['errors'], $_POST);
            $this->redirect($back);
            return;
        }

        $this->notifyOwner($form, $_POST);

        $this->ctx->log()->info('Form submission received', [
            'form_id'       => $formId,
            'submission_id' => $result['submission_id'],
        ]);

        // Deliberately no session flash on the happy path: the component
        // renders the form's own success_message off `?form_submitted=<id>`,
        // so a successful submission leaves the visitor without a cookie and
        // the landing page stays shareable between visitors.
        $this->redirect($this->withQueryParam($back, 'form_submitted', $formId));
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $payload
     */
    private function notifyOwner(array $form, array $payload): void
    {
        $to = trim((string) ($form['notify_email'] ?? ''));
        if ($to === '') {
            return;
        }

        $lines = [];
        foreach ($payload as $k => $v) {
            if (in_array($k, ['_csrf_token', 'form_id', 'return_to', 'website', 'cf-turnstile-response', 'g-recaptcha-response'], true)) {
                continue;
            }
            if (is_array($v)) {
                $v = implode(', ', array_map('strval', $v));
            }
            $lines[] = $k . ': ' . $v;
        }

        try {
            $this->ctx->mail()->send(
                $to,
                'New submission: ' . (string) $form['name'],
                implode("\n", $lines),
                ['html' => false]
            );
        } catch (\Throwable $e) {
            $this->ctx->log()->error('Notification email failed', [
                'to'      => $to,
                'form_id' => $form['id'] ?? null,
            ], $e);
        }
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $values
     */
    private function flash(string $formId, string $type, string $message, array $errors, array $values): void
    {
        if ($formId === '') {
            return;
        }
        typedock_session_start();
        unset($values['_csrf_token'], $values['cf-turnstile-response'], $values['g-recaptcha-response']);
        $_SESSION['form_flash.' . $formId] = [
            'type'    => $type,
            'message' => $message,
            'errors'  => $errors,
            'values'  => $values,
        ];
    }

    private function sanitiseReturnPath(string $raw): string
    {
        if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//')) {
            return '/';
        }
        return $raw;
    }

    private function redirect(string $path): void
    {
        \Flight::redirect($path);
        exit;
    }

    private function withQueryParam(string $path, string $key, string $value): string
    {
        $fragment = '';
        if (str_contains($path, '#')) {
            [$path, $fragment] = explode('#', $path, 2);
            $fragment = '#' . $fragment;
        }

        $query = '';
        if (str_contains($path, '?')) {
            [$path, $query] = explode('?', $path, 2);
        }

        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }
        $params[$key] = $value;

        return $path . '?' . http_build_query($params) . $fragment;
    }
}
