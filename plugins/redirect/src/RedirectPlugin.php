<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Redirect;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class RedirectPlugin implements PluginInterface
{
    public function register(PluginContext $ctx): void
    {
        $ctx->migrate(__DIR__ . '/../migrations');

        $pdo = $ctx->db()->pdo();
        $ctx->addRedirectResolver(new ExactMatchResolver($pdo));
        $ctx->addRedirectResolver(new RegexResolver($pdo));

        $controller = new RedirectAdminController($ctx);
        $ctx->registerAdminRoute('GET',  '',           [$controller, 'index'], permission: 'redirects:manage');
        $ctx->registerAdminRoute('POST', '',           [$controller, 'store'], permission: 'redirects:manage');
        $ctx->registerAdminRoute('POST', 'import',     [$controller, 'import'], permission: 'redirects:manage');
        $ctx->registerAdminRoute(
            'POST',
            '@id/delete',
            fn(string $id) => $controller->destroy($id),
            permission: 'redirects:manage',
        );

        $ctx->addAdminMenuItem('Redirects', '', 'redirects:manage');
    }

    public function getName(): string
    {
        return 'Redirect';
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
