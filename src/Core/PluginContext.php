<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Component\ComponentDefinition;

class PluginContext
{
    public function __construct(
        private readonly string $pluginSlug,
        private readonly \PDO $pdo
    ) {}

    public function registerComponent(ComponentDefinition $def): void
    {
        \Flight::components()->register($def);
    }

    public function registerRoute(string $method, string $path, callable $handler): void
    {
        $prefixed = '/plugin/' . $this->pluginSlug . '/' . ltrim($path, '/');
        \Flight::route(strtoupper($method) . ' ' . $prefixed, $handler);
    }

    public function db(): PluginDatabase
    {
        return new PluginDatabase($this->pdo, $this->pluginSlug);
    }

    public function getSiteOption(string $key): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM site_options WHERE key_name = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return json_decode((string) $row['value'], true);
    }

    public function getCurrentUser(): ?array
    {
        return \Flight::get('current_user');
    }

    public function render(string $template, array $params = []): string
    {
        return \Flight::latte()->renderToString($template, $params);
    }
}
