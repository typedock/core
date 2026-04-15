<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class SettingsController extends BaseAdminController
{
    public function general(): void
    {
        $this->render('pages/settings/general.latte', [
            'options'       => $this->getOptions('general'),
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
