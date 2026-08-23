<?php
declare(strict_types=1);

namespace TypeDock\Content;

class SiteService
{
    /** @var array<string, mixed> */
    private array $options;

    private readonly MenuTreeResolver $menuResolver;

    /** @var array<string, array<MenuItem>> */
    private array $menuCache = [];

    public function __construct(private readonly \PDO $pdo)
    {
        $this->options      = $this->loadOptions();
        $this->menuResolver = new MenuTreeResolver($pdo);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'name'              => $this->options['site.name'] ?? config('app.name', 'TypeDock'),
            'url'               => config('app.url', 'http://localhost'),
            'locale'            => typedock_current_locale(),
            'homeMode'          => $this->options['site.home_mode'] ?? 'archive',
            'homePageId'        => $this->options['site.home_page_id'] ?? null,
            'postsArchiveSlug'  => $this->postsArchiveSlug(),
            'postsArchiveLabel' => $this->options['site.posts_archive_label'] ?? 'Blog',
            'faviconId'         => $this->options['site.favicon_id'] ?? null,
            'faviconUrl'        => $this->faviconUrl(),
            default             => null,
        };
    }

    public function __isset(string $name): bool
    {
        return in_array(
            $name,
            ['name', 'url', 'locale', 'homeMode', 'homePageId', 'postsArchiveSlug', 'postsArchiveLabel', 'faviconId', 'faviconUrl'],
            true
        );
    }

    public function option(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Build a root-relative URL for a post slug under the configured archive.
     * Use from Latte: `<a href="{$site->postUrl($post->slug)}">`.
     */
    public function postUrl(string $slug = ''): string
    {
        // slug_path() percent-encodes: a slug may be in any script, and this
        // value goes straight into an href.
        $prefix = '/' . $this->postsArchiveSlug();
        return $slug === '' ? $prefix : $prefix . slug_path($slug);
    }

    /**
     * Resolve the public URL of the site favicon / icon if set.
     */
    public function faviconUrl(): ?string
    {
        $id = $this->options['site.favicon_id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            return null;
        }
        try {
            if (class_exists('\Flight', false) && \Flight::has('media_service')) {
                $media = \Flight::media_service()->find(trim($id));
                if ($media !== null && !empty($media['url'])) {
                    return (string) $media['url'];
                }
            }
            $stmt = $this->pdo->prepare('SELECT path FROM media WHERE id = ? LIMIT 1');
            $stmt->execute([trim($id)]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            $path = (string) $row['path'];
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            $baseUrl = rtrim((string) config('app.url', ''), '/');
            return $baseUrl . '/uploads/' . ltrim($path, '/');
        } catch (\Throwable) {
            return null;
        }
    }

    private function postsArchiveSlug(): string
    {
        $slug = (string) ($this->options['site.posts_archive_slug'] ?? 'blog');
        $slug = trim($slug, '/');
        return $slug !== '' ? $slug : 'blog';
    }

    /**
     * @return array<MenuItem>
     */
    public function menu(string $location): array
    {
        $locale = typedock_current_locale();
        $key    = $locale . ':' . $location;
        if (!isset($this->menuCache[$key])) {
            $this->menuCache[$key] = $this->menuResolver->resolve($location, $locale);
        }
        return $this->menuCache[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOptions(): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT key_name, value FROM site_options WHERE group_name IN ('general', 'seo')");
            $stmt->execute();
            $opts = [];
            foreach ($stmt->fetchAll() as $row) {
                $opts[$row['key_name']] = json_decode((string) $row['value'], true);
            }
            return $opts;
        } catch (\Throwable) {
            return [];
        }
    }
}
