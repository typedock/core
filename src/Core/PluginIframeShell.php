<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Admin\BaseAdminController;

/**
 * Renders the Core admin shell (sidebar / header / nav) wrapped around an
 * <iframe> that points at the currently requested URL with `_iframed=1`
 * appended. Plugin admin routes return naked content through plugin-ui.latte
 * on the iframe side; this shell supplies the chrome on the outer page.
 *
 * The plugin being embedded never touches Core's admin CSS or Tailwind
 * contract — CSS changes inside the iframe can't leak out, and admin chrome
 * changes can't leak in. That's the doc28 §2.1 guarantee.
 */
class PluginIframeShell extends BaseAdminController
{
    public function __construct(private readonly string $pluginSlug) {}

    public function dispatch(): void
    {
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $inner  = $this->appendQuery($uri, '_iframed=1');
        $title  = $this->pluginTitle();

        $this->render('layouts/plugin-shell.latte', [
            'plugin_slug'  => $this->pluginSlug,
            'plugin_title' => $title,
            'iframe_src'   => $inner,
        ]);
    }

    private function pluginTitle(): string
    {
        foreach (\Flight::plugin_admin_menu()->all() as $item) {
            if ($item['slug'] === $this->pluginSlug) {
                return $item['label'];
            }
        }
        return ucfirst($this->pluginSlug);
    }

    private function appendQuery(string $url, string $kv): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $kv;
    }
}
