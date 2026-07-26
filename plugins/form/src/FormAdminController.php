<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

use TypeDock\Core\PluginContext;

/**
 * Admin controller for the Form plugin — talks to Core only through
 * PluginContext (no BaseAdminController inheritance). Renders templates
 * under plugins/form/templates/ which extend Core's plugin-ui layout, so
 * the entire admin page is delivered naked inside the iframe.
 */
class FormAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    private function service(): FormService
    {
        return new FormService($this->ctx->db()->pdo());
    }

    public function index(): void
    {
        $this->ctx->view('templates/admin/index.latte', [
            'forms'         => $this->service()->listForms(),
            'verify_csrf'   => (bool) $this->ctx->getSiteOption(FormCsrf::OPTION),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    /**
     * Strict mode: verify a CSRF token on every submission, including
     * anonymous ones. Off by default because it forces a session cookie onto
     * every visitor who sees a form, which takes those pages out of the CDN.
     */
    public function updateSettings(): void
    {
        $this->ctx->setSiteOption(FormCsrf::OPTION, isset($_POST['verify_csrf']), 'plugin.form');
        $this->ctx->redirect($this->ctx->adminUrl(), 'Form settings saved.');
    }

    public function create(): void
    {
        $this->ctx->view('templates/admin/edit.latte', [
            'form'        => null,
            'fields'      => $this->defaultFields(),
            'field_types' => self::FIELD_TYPES,
        ]);
    }

    public function store(): void
    {
        $payload = $this->collectPayload();
        $id      = $this->service()->create($payload);
        $this->ctx->log()->info('Form created', ['id' => $id, 'name' => $payload['name']]);
        $this->ctx->redirect($this->ctx->adminUrl($id . '/edit'), 'Form created.');
    }

    public function edit(string $id): void
    {
        $form = $this->service()->find($id);
        if ($form === null) {
            $this->ctx->redirect($this->ctx->adminUrl(), 'Form not found.', 'error');
            return;
        }
        $fields = $this->service()->decodeFields($form['fields'] ?? null);
        $this->ctx->view('templates/admin/edit.latte', [
            'form'          => $form,
            'fields'        => $fields === [] ? $this->defaultFields() : $fields,
            'field_types'   => self::FIELD_TYPES,
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $payload = $this->collectPayload();
        $this->service()->update($id, $payload);
        $this->ctx->redirect($this->ctx->adminUrl($id . '/edit'), 'Form saved.');
    }

    public function destroy(string $id): void
    {
        $this->service()->delete($id);
        $this->ctx->log()->info('Form deleted', ['id' => $id]);
        $this->ctx->redirect($this->ctx->adminUrl(), 'Form deleted.');
    }

    public function submissions(string $id): void
    {
        $form = $this->service()->find($id);
        if ($form === null) {
            $this->ctx->redirect($this->ctx->adminUrl(), 'Form not found.', 'error');
            return;
        }
        $service = $this->service();
        $this->ctx->view('templates/admin/submissions.latte', [
            'form'        => $form,
            'fields'      => $service->decodeFields($form['fields'] ?? null),
            'submissions' => $service->listSubmissions($id),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseFieldRepeater(): array
    {
        $raw = $_POST['fields'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $options = [];
            $optText = trim((string) ($row['options_text'] ?? ''));
            if ($optText !== '') {
                foreach (preg_split('/\r?\n/', $optText) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $parts = explode('|', $line, 2);
                    $v     = trim($parts[0]);
                    $l     = trim($parts[1] ?? $v);
                    if ($v !== '') {
                        $options[] = ['value' => $v, 'label' => $l];
                    }
                }
            }
            $out[] = [
                'name'        => $name,
                'label'       => trim((string) ($row['label'] ?? $name)),
                'type'        => (string) ($row['type'] ?? 'text'),
                'required'    => !empty($row['required']),
                'placeholder' => (string) ($row['placeholder'] ?? ''),
                'options'     => $options,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPayload(): array
    {
        return [
            'name'            => (string) ($_POST['name'] ?? ''),
            'slug'            => (string) ($_POST['slug'] ?? ''),
            'fields'          => $this->parseFieldRepeater(),
            'notify_email'    => (string) ($_POST['notify_email'] ?? ''),
            'success_message' => (string) ($_POST['success_message'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function defaultFields(): array
    {
        return [
            ['name' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true, 'placeholder' => 'Your name',        'options' => []],
            ['name' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true, 'placeholder' => 'you@example.com',  'options' => []],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'placeholder' => '',                 'options' => []],
        ];
    }

    private const FIELD_TYPES = [
        'text'     => 'Text',
        'email'    => 'Email',
        'tel'      => 'Phone',
        'url'      => 'URL',
        'number'   => 'Number',
        'date'     => 'Date',
        'textarea' => 'Long text',
        'select'   => 'Dropdown',
        'checkbox' => 'Checkbox',
        'radio'    => 'Radio group',
    ];
}
