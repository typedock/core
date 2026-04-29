<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Backup;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

/**
 * Backup plugin — creates, downloads, and restores tar.gz archives that
 * bundle a SQL dump of the database and the storage/uploads tree.
 *
 * The `backups` history table is owned by Core's schema (it lives in the
 * initial migration set so it is present even when this plugin is
 * disabled — disabling shouldn't drop history). The plugin only owns the
 * runtime: the service, the admin UI, and the create/download/restore
 * flow.
 */
final class BackupPlugin implements PluginInterface
{
    public function register(PluginContext $ctx): void
    {
        $controller = new BackupAdminController($ctx);
        $ctx->registerAdminRoute('GET',  '',                  [$controller, 'index']);
        $ctx->registerAdminRoute('POST', '',                  [$controller, 'create']);
        $ctx->registerAdminRoute('GET',  '@id/download',      fn(string $id) => $controller->download($id));
        $ctx->registerAdminRoute('POST', '@id/restore',       fn(string $id) => $controller->restore($id));
        $ctx->registerAdminRoute('POST', '@id/delete',        fn(string $id) => $controller->destroy($id));

        $ctx->addAdminMenuItem('Backups', '');
    }

    public function getName(): string
    {
        return 'Backup';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return [];
    }
}
