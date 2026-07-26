<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;
use TypeDock\Middleware\CsrfMiddleware;

class FormDataProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $formId  = trim((string) ($params['form_id'] ?? ''));
        $slug    = trim((string) ($params['slug'] ?? ''));
        $service = new FormService(\Flight::db());

        $form = null;
        if ($formId !== '') {
            $form = $service->find($formId);
        }
        // Slot defaults reference forms by slug (`{"slug":"newsletter"}`)
        // so the slot keeps working when the underlying form id changes.
        if ($form === null && $slug !== '') {
            $form   = $service->findBySlug($slug);
            $formId = $form !== null ? (string) $form['id'] : '';
        }

        $fields = $form !== null ? $service->decodeFields($form['fields'] ?? null) : [];

        return [
            'form'         => $form,
            'fields'       => $fields,
            // Issuing a token opens a session, which makes the whole page
            // uncacheable — so only strict mode pays that price.
            'csrf_token'   => FormCsrf::required() ? CsrfMiddleware::generate() : '',
            'captcha_html' => $form !== null ? \Flight::captcha()->render('form_submit', [
                'form_id' => $formId,
            ]) : '',
            'flash'        => $this->resolveFlash($formId, $form),
        ];
    }

    /**
     * Success is carried in the URL (`?form_submitted=<id>`) and rendered from
     * the form's own configured message, so the happy path never touches a
     * session and the page stays cacheable. Only the error path — which has
     * to redisplay what the visitor typed — falls back to a session flash.
     *
     * @param array<string, mixed>|null $form
     * @return array{type: string, message: string, errors: array<string, string>, values: array<string, mixed>}|null
     */
    private function resolveFlash(string $formId, ?array $form): ?array
    {
        if ($formId === '') {
            return null;
        }

        $flash = $this->consumeSessionFlash($formId);
        if ($flash !== null) {
            return $flash;
        }

        if ($form === null || (string) ($_GET['form_submitted'] ?? '') !== $formId) {
            return null;
        }

        $message = trim((string) ($form['success_message'] ?? ''));

        return [
            'type'    => 'success',
            'message' => $message !== '' ? $message : 'Thanks — your submission has been received.',
            'errors'  => [],
            'values'  => [],
        ];
    }

    /**
     * @return array{type: string, message: string, errors: array<string, string>, values: array<string, mixed>}|null
     */
    private function consumeSessionFlash(string $formId): ?array
    {
        // No session cookie means there is nothing to read, and starting a
        // session to find that out would cost this visitor the CDN.
        if (session_status() !== PHP_SESSION_ACTIVE
            && ($_COOKIE[typedock_session_cookie_name()] ?? '') === '') {
            return null;
        }

        typedock_session_start();
        $key   = 'form_flash.' . $formId;
        $flash = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        $this->endEmptySession();

        return is_array($flash) ? $flash : null;
    }

    /**
     * Drop the session cookie once the last thing in it has been consumed.
     * Without this, a single failed submission would leave an anonymous
     * visitor with a session cookie for its whole lifetime, and every page
     * they viewed afterwards would bypass the CDN.
     */
    private function endEmptySession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $_SESSION !== []) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);

        session_destroy();
    }
}
