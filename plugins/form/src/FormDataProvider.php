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
            'csrf_token'   => CsrfMiddleware::generate(),
            'captcha_html' => $form !== null ? \Flight::captcha()->render('form_submit', [
                'form_id' => $formId,
            ]) : '',
            'flash'        => $this->consumeFlash($formId),
        ];
    }

    /**
     * @return array{type: string, message: string, errors: array<string, string>, values: array<string, mixed>}|null
     */
    private function consumeFlash(string $formId): ?array
    {
        if ($formId === '') {
            return null;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $key   = 'form_flash.' . $formId;
        $flash = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_array($flash) ? $flash : null;
    }
}
