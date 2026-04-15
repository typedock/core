<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class UserController extends BaseAdminController
{
    public function index(): void
    {
        $stmt  = \Flight::db()->query('SELECT id, email, name, role, last_login_at, created_at FROM users ORDER BY created_at DESC');
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

        $pdo->prepare(
            'INSERT INTO users (id, email, password_hash, name, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            trim($_POST['email'] ?? ''),
            $hash,
            trim($_POST['name'] ?? ''),
            $_POST['role'] ?? 'contributor',
            $now, $now,
        ]);

        $this->redirect('/admin/users', 'User created successfully.');
    }

    public function edit(string $id): void
    {
        $stmt = \Flight::db()->prepare('SELECT id, email, name, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
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

        $updates = [
            'name'       => trim($_POST['name'] ?? ''),
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
}
