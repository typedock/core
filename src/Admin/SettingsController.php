<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class SettingsController extends BaseAdminController
{
    public function general(): void
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->query(
            "SELECT id, title FROM pages WHERE page_type = 'page' AND status = 'published' ORDER BY title ASC"
        );
        $pages = $stmt !== false ? $stmt->fetchAll() : [];

        $this->render('pages/settings/general.latte', [
            'options'       => $this->getOptions('general'),
            'pages'         => $pages,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function updateGeneral(): void
    {
        $this->setOption('site.name', $_POST['site_name'] ?? '', 'general');
        $this->setOption('site.description', $_POST['site_description'] ?? '', 'general');
        $this->setOption('scripts.head', $_POST['scripts_head'] ?? '', 'general');
        $this->setOption('scripts.body', $_POST['scripts_body'] ?? '', 'general');

        // Home page + posts archive settings.
        $homeMode   = $_POST['home_mode'] ?? 'archive';
        $homeMode   = in_array($homeMode, ['archive', 'page'], true) ? $homeMode : 'archive';
        $homePageId = trim((string) ($_POST['home_page_id'] ?? ''));
        if ($homeMode !== 'page') {
            $homePageId = '';
        }

        // Sanitise the posts archive slug: strip slashes, whitespace and any
        // character that would break a URL path segment. Fall back to 'blog'
        // if the operator clears it entirely.
        $postsSlug = trim((string) ($_POST['posts_archive_slug'] ?? 'blog'), "/ \t\n\r\0\x0B");
        $postsSlug = preg_replace('/[^A-Za-z0-9\-_]/', '', $postsSlug) ?? '';

        // Reserved slugs that would collide with hard-coded routes. Falling
        // back to 'blog' instead of bouncing the submission keeps the save
        // lenient — the UI hint warns about this separately.
        $reserved = ['admin', 'api', 'search', 'category', 'tag', 'sitemap.xml', 'feed', 'robots.txt', 'page', 'install.php'];
        if ($postsSlug === '' || in_array(strtolower($postsSlug), $reserved, true)) {
            $postsSlug = 'blog';
        }

        $postsLabel = trim((string) ($_POST['posts_archive_label'] ?? 'Blog'));
        if ($postsLabel === '') {
            $postsLabel = 'Blog';
        }

        $this->setOption('site.home_mode', $homeMode, 'general');
        $this->setOption('site.home_page_id', $homePageId !== '' ? $homePageId : null, 'general');
        $this->setOption('site.posts_archive_slug', $postsSlug, 'general');
        $this->setOption('site.posts_archive_label', $postsLabel, 'general');

        $this->redirect('/admin/settings/general', 'Settings saved successfully.');
    }

    public function seo(): void
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare("SELECT * FROM seo_meta WHERE target_type = 'global' AND target_id IS NULL LIMIT 1");
        $stmt->execute();
        $seo = $stmt->fetch() ?: [];
        $this->render('pages/settings/seo.latte', [
            'seo'           => $seo,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function updateSeo(): void
    {
        $seoService = new \TypeDock\Seo\SeoService(\Flight::db());
        $seoService->upsert('global', null, [
            'seo_title'        => $_POST['seo_title'] ?? null,
            'meta_description' => $_POST['meta_description'] ?? null,
            'robots'           => $_POST['robots'] ?? null,
        ]);
        $this->redirect('/admin/settings/seo', 'SEO settings saved successfully.');
    }

    public function modules(): void
    {
        $this->render('pages/settings/modules.latte', [
            'modules' => config('modules.modules', []),
            'plugins' => config('modules.plugins', []),
        ]);
    }

    /** @return array<string, mixed> */
    private function getOptions(string $group): array
    {
        try {
            $stmt = \Flight::db()->prepare("SELECT key_name, value FROM site_options WHERE group_name = ?");
            $stmt->execute([$group]);
            $rows = $stmt->fetchAll();
            $opts = [];
            foreach ($rows as $row) {
                $opts[$row['key_name']] = json_decode((string) $row['value'], true);
            }
            return $opts;
        } catch (\Throwable) {
            return [];
        }
    }

    private function setOption(string $key, mixed $value, string $group): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $pdo = \Flight::db();

        $stmt = $pdo->prepare("SELECT key_name FROM site_options WHERE key_name = ? LIMIT 1");
        $stmt->execute([$key]);

        if ($stmt->fetch() !== false) {
            $pdo->prepare("UPDATE site_options SET value = ?, updated_at = ? WHERE key_name = ?")
                ->execute([json_encode($value), $now, $key]);
        } else {
            $pdo->prepare("INSERT INTO site_options (key_name, value, group_name, updated_at) VALUES (?, ?, ?, ?)")
                ->execute([$key, json_encode($value), $group, $now]);
        }
    }
}
