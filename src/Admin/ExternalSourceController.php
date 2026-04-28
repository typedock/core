<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Exception\ValidationException;

final class ExternalSourceController extends BaseAdminController
{
    public function index(): void
    {
        $this->render('pages/external-sources/index.latte', [
            'sources' => \Flight::external_sources()->list(),
            'adapters_by_id' => $this->adaptersById(),
            'flash_success' => $this->getFlash('success'),
            'flash_error' => $this->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $this->render('pages/external-sources/edit.latte', [
            'source' => $this->blankSource(),
            'form_action' => '/admin/external-sources/create',
            'is_new' => true,
            'errors' => [],
            'adapters' => \Flight::external_sources()->availableAdapters(),
            'adapters_by_id' => $this->adaptersById(),
            'field_discovery' => null,
            'flash_success' => $this->getFlash('success'),
            'flash_error' => $this->getFlash('error'),
        ]);
    }

    public function store(): void
    {
        try {
            $source = \Flight::external_sources()->create($_POST);
            $this->redirect('/admin/external-sources/' . $source['id'] . '/edit', 'Connection verified. Configure field mapping and detail template.');
        } catch (ValidationException $e) {
            $this->renderEditFromPost('/admin/external-sources/create', true, $e->getErrors());
        } catch (\Throwable $e) {
            $this->redirect('/admin/external-sources/create', $e->getMessage(), 'error');
        }
    }

    public function edit(string $id): void
    {
        $source = \Flight::external_sources()->find($id);
        if ($source === null) {
            $this->redirect('/admin/external-sources', 'External Source not found.', 'error');
            return;
        }

        $this->render('pages/external-sources/edit.latte', [
            'source' => $source,
            'diagnostics' => \Flight::external_sources()->rawCredentialsForDiagnostics($source),
            'form_action' => '/admin/external-sources/' . $id . '/edit',
            'is_new' => false,
            'errors' => [],
            'adapters' => \Flight::external_sources()->availableAdapters(),
            'adapters_by_id' => $this->adaptersById(),
            'field_discovery' => null,
            'flash_success' => $this->getFlash('success'),
            'flash_error' => $this->getFlash('error'),
        ]);
    }

    public function update(string $id): void
    {
        try {
            \Flight::external_sources()->update($id, $_POST);
            $this->redirect('/admin/external-sources/' . $id . '/edit', 'External Source saved.');
        } catch (ValidationException $e) {
            $source = \Flight::external_sources()->find($id) ?? $this->blankSource();
            $source = $this->mergePost($source);
            $this->render('pages/external-sources/edit.latte', [
                'source' => $source,
                'diagnostics' => \Flight::external_sources()->rawCredentialsForDiagnostics($source),
                'form_action' => '/admin/external-sources/' . $id . '/edit',
                'is_new' => false,
                'errors' => $e->getErrors(),
                'adapters' => \Flight::external_sources()->availableAdapters(),
                'adapters_by_id' => $this->adaptersById(),
                'field_discovery' => null,
                'flash_error' => 'Please fix the highlighted fields.',
            ]);
        } catch (\Throwable $e) {
            $this->redirect('/admin/external-sources/' . $id . '/edit', $e->getMessage(), 'error');
        }
    }

    public function destroy(string $id): void
    {
        \Flight::external_sources()->delete($id);
        $this->redirect('/admin/external-sources', 'External Source deleted.');
    }

    public function clearCache(string $id): void
    {
        \Flight::external_sources()->clearCache($id);
        $this->redirect('/admin/external-sources/' . $id . '/edit', 'External Source cache cleared.');
    }

    public function discoverFields(string $id): void
    {
        $source = \Flight::external_sources()->find($id);
        if ($source === null) {
            $this->redirect('/admin/external-sources', 'External Source not found.', 'error');
            return;
        }

        $source = $this->mergePost($source);
        try {
            $fields = \Flight::external_sources()->discoverFields($id, $_POST);
            $this->render('pages/external-sources/edit.latte', [
                'source' => $source,
                'diagnostics' => \Flight::external_sources()->rawCredentialsForDiagnostics($source),
                'form_action' => '/admin/external-sources/' . $id . '/edit',
                'is_new' => false,
                'errors' => [],
                'adapters' => \Flight::external_sources()->availableAdapters(),
                'adapters_by_id' => $this->adaptersById(),
                'field_discovery' => [
                    'fields' => $fields,
                    'count' => count($fields),
                ],
                'flash_success' => 'Fetched ' . count($fields) . ' fields from ' . $this->providerLabel($source) . '.',
                'flash_error' => null,
            ]);
        } catch (ValidationException $e) {
            $this->render('pages/external-sources/edit.latte', [
                'source' => $source,
                'diagnostics' => \Flight::external_sources()->rawCredentialsForDiagnostics($source),
                'form_action' => '/admin/external-sources/' . $id . '/edit',
                'is_new' => false,
                'errors' => $e->getErrors(),
                'adapters' => \Flight::external_sources()->availableAdapters(),
                'adapters_by_id' => $this->adaptersById(),
                'field_discovery' => null,
                'flash_error' => 'Could not fetch fields from ' . $this->providerLabel($source) . '.',
            ]);
        } catch (\Throwable $e) {
            $this->redirect('/admin/external-sources/' . $id . '/edit', $e->getMessage(), 'error');
        }
    }

    /**
     * @param array<string, string[]> $errors
     */
    private function renderEditFromPost(string $action, bool $isNew, array $errors): void
    {
        $this->render('pages/external-sources/edit.latte', [
            'source' => $this->mergePost($this->blankSource()),
            'diagnostics' => ['has_delivery_token' => false, 'rotation_note' => 'APP_KEY rotation requires re-entering External Source credentials.'],
            'form_action' => $action,
            'is_new' => $isNew,
            'errors' => $errors,
            'adapters' => \Flight::external_sources()->availableAdapters(),
            'adapters_by_id' => $this->adaptersById(),
            'field_discovery' => null,
            'flash_error' => 'Please fix the highlighted fields.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function blankSource(): array
    {
        return \Flight::external_sources()->blankSource((string) ($_POST['provider'] ?? 'contentful'));
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function mergePost(array $source): array
    {
        $provider = (string) ($_POST['provider'] ?? $source['provider'] ?? 'contentful');
        if (!isset($this->adaptersById()[$provider])) {
            $provider = 'contentful';
        }
        $source['provider'] = $provider;
        $source['name'] = (string) ($_POST['name'] ?? $source['name'] ?? '');
        $source['slug'] = (string) ($_POST['slug'] ?? $source['slug'] ?? '');
        $source['status'] = (string) ($_POST['status'] ?? $source['status'] ?? 'active');
        $source['cache_ttl_seconds'] = (int) ($_POST['cache_ttl_seconds'] ?? $source['cache_ttl_seconds'] ?? 600);
        $source['detail_template'] = (string) ($_POST['detail_template'] ?? $source['detail_template'] ?? '');
        $source['config'] = is_array($source['config'] ?? null) ? $source['config'] : [];
        $adapter = $this->adaptersById()[$provider] ?? null;
        foreach (($adapter['config_fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $source['config'][$name] = (string) ($_POST[$name] ?? $source['config'][$name] ?? $adapter['default_config'][$name] ?? '');
            }
        }
        foreach (['slug', 'title', 'excerpt', 'thumbnail', 'date', 'category', 'tags', 'content'] as $field) {
            $source['field_mapping'][$field] = (string) ($_POST['field_' . $field] ?? $source['field_mapping'][$field] ?? '');
        }
        return $source;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function providerLabel(array $source): string
    {
        return (string) ($this->adaptersById()[(string) ($source['provider'] ?? 'contentful')]['label'] ?? 'External Source');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function adaptersById(): array
    {
        $byId = [];
        foreach (\Flight::external_sources()->availableAdapters() as $adapter) {
            $byId[(string) $adapter['id']] = $adapter;
        }
        return $byId;
    }
}
