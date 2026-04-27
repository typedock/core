<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\TermSlugger;

class UserController extends BaseAdminController
{
    public function index(): void
    {
        $stmt  = \Flight::db()->query('SELECT id, email, name, display_name, slug, role, last_login_at, created_at FROM users ORDER BY created_at DESC');
        $users = $stmt ? $stmt->fetchAll() : [];
        $this->render('pages/users/index.latte', [
            'users'         => $users,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $this->render('pages/users/edit.latte', [
            'user'        => null,
            'form_action' => '/admin/users/create',
        ]);
    }

    public function store(): void
    {
        $pdo  = \Flight::db();
        $id   = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $hash = password_hash($_POST['password'] ?? '', PASSWORD_BCRYPT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));

        $pdo->prepare(
            'INSERT INTO users (id, email, password_hash, name, display_name, slug, bio, avatar_media_id, website_url, social_links, role, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            trim($_POST['email'] ?? ''),
            $hash,
            $name,
            $displayName ?: null,
            $this->resolveAuthorSlug($id, (string) ($_POST['slug'] ?? ''), $displayName !== '' ? $displayName : $name),
            trim((string) ($_POST['bio'] ?? '')) ?: null,
            trim((string) ($_POST['avatar_media_id'] ?? '')) ?: null,
            $this->normalizeUrl((string) ($_POST['website_url'] ?? '')),
            $this->normalizeSocialLinks((string) ($_POST['social_links'] ?? '')),
            $_POST['role'] ?? 'contributor',
            $now, $now,
        ]);

        $this->redirect('/admin/users', 'User created successfully.');
    }

    public function edit(string $id): void
    {
        $stmt = \Flight::db()->prepare(
            'SELECT u.id, u.email, u.name, u.display_name, u.slug, u.bio, u.avatar_media_id,
                    u.website_url, u.social_links, u.role, m.path AS avatar_path_current
             FROM users u
             LEFT JOIN media m ON m.id = u.avatar_media_id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if ($user !== false && !empty($user['avatar_path_current'])) {
            $user['avatar_url'] = \Flight::storage()->url((string) $user['avatar_path_current']);
        }
        $this->render('pages/users/edit.latte', [
            'user'          => $user,
            'form_action'   => '/admin/users/' . $id . '/edit',
            'flash_success' => $this->getFlash('success'),
        ]);
    }

    public function update(string $id): void
    {
        $pdo = \Flight::db();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $name = trim((string) ($_POST['name'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));

        $updates = [
            'name'       => $name,
            'display_name' => $displayName ?: null,
            'slug' => $this->resolveAuthorSlug(
                $id,
                (string) ($_POST['slug'] ?? ''),
                $displayName !== '' ? $displayName : $name
            ),
            'bio' => trim((string) ($_POST['bio'] ?? '')) ?: null,
            'avatar_media_id' => trim((string) ($_POST['avatar_media_id'] ?? '')) ?: null,
            'website_url' => $this->normalizeUrl((string) ($_POST['website_url'] ?? '')),
            'social_links' => $this->normalizeSocialLinks((string) ($_POST['social_links'] ?? '')),
            'role'       => $_POST['role'] ?? 'contributor',
            'updated_at' => $now,
        ];

        if (!empty($_POST['password'])) {
            $updates['password_hash'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
        }

        $setClauses = [];
        $params     = [];
        foreach ($updates as $col => $val) {
            $setClauses[] = $col . ' = ?';
            $params[]     = $val;
        }
        $params[] = $id;

        $pdo->prepare('UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = ?')->execute($params);
        $this->redirect('/admin/users', 'User updated successfully.');
    }

    public function destroy(string $id): void
    {
        $currentUser = \Flight::get('current_user');
        if (($currentUser['id'] ?? null) === $id) {
            $this->redirect('/admin/users', 'You cannot delete your own account.');
            return;
        }
        \Flight::db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        $this->redirect('/admin/users', 'User deleted successfully.');
    }

    private function resolveAuthorSlug(string $userId, string $slug, string $fallbackName): string
    {
        $slug = trim($slug);
        $base = $slug !== ''
            ? TermSlugger::normalize($slug, 'author-' . date('YmdHis'))
            : TermSlugger::fromName($fallbackName, 'author');

        $candidate = $base;
        $suffix    = 2;
        while ($this->authorSlugExists($candidate, $userId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    private function authorSlugExists(string $slug, string $exceptUserId): bool
    {
        $stmt = \Flight::db()->prepare('SELECT id FROM users WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$slug, $exceptUserId]);
        return $stmt->fetch() !== false;
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        return $url;
    }

    private function normalizeSocialLinks(string $json): ?string
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $url = $this->normalizeUrl($value);
            if ($url !== null) {
                $out[$key] = $url;
            }
        }

        return $out !== [] ? json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }
}
