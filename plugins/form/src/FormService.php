<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

use Ramsey\Uuid\Uuid;

/**
 * Persistence + business logic for Form plugin definitions and submissions.
 * Tables are created by migrations/0001_create_tables.sql, invoked via
 * $ctx->migrate() during plugin register().
 */
class FormService
{
    public const TABLE_FORMS       = 'plugin_form_forms';
    public const TABLE_SUBMISSIONS = 'plugin_form_submissions';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listForms(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE_FORMS . ' ORDER BY name');
            return $stmt ? $stmt->fetchAll() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE_FORMS . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a form by its operator-set slug. Themes use this so theme.json
     * can declare `{"component":"form","params":{"slug":"newsletter"}}`
     * without baking a UUID into the theme.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE_FORMS . ' WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): string
    {
        $id  = Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE_FORMS . ' (id, name, slug, fields, notify_email, success_message, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            (string) ($payload['name'] ?? 'Untitled form'),
            $this->normalizeSlug($payload['slug'] ?? null),
            $this->encodeFields($payload['fields'] ?? []),
            $this->nullableTrim($payload['notify_email'] ?? null),
            $this->nullableTrim($payload['success_message'] ?? null),
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(string $id, array $payload): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'UPDATE ' . self::TABLE_FORMS . "
             SET name = ?, slug = ?, fields = ?, notify_email = ?, success_message = ?, updated_at = ?
             WHERE id = ?"
        )->execute([
            (string) ($payload['name'] ?? 'Untitled form'),
            $this->normalizeSlug($payload['slug'] ?? null),
            $this->encodeFields($payload['fields'] ?? []),
            $this->nullableTrim($payload['notify_email'] ?? null),
            $this->nullableTrim($payload['success_message'] ?? null),
            $now,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE_SUBMISSIONS . ' WHERE form_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM ' . self::TABLE_FORMS . ' WHERE id = ?')->execute([$id]);
    }

    /**
     * @param  mixed $raw
     * @return array<int, array<string, mixed>>
     */
    public function decodeFields(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return [];
        }

        $allowed = ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'checkbox', 'radio'];
        $out     = [];
        foreach ($raw as $f) {
            if (!is_array($f)) {
                continue;
            }
            $name  = trim((string) ($f['name'] ?? ''));
            $label = trim((string) ($f['label'] ?? $name));
            if ($name === '' || preg_match('/^[a-z][a-z0-9_]{0,50}$/i', $name) !== 1) {
                continue;
            }
            $type = (string) ($f['type'] ?? 'text');
            if (!in_array($type, $allowed, true)) {
                $type = 'text';
            }
            $out[] = [
                'name'        => $name,
                'label'       => $label,
                'type'        => $type,
                'required'    => (bool) ($f['required'] ?? false),
                'options'     => $this->cleanOptions($f['options'] ?? []),
                'placeholder' => (string) ($f['placeholder'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param  array<string, mixed> $form
     * @param  array<string, mixed> $input
     * @return array{ok: bool, errors: array<string, string>, submission_id: ?string}
     */
    public function submit(array $form, array $input): array
    {
        $fields = $this->decodeFields($form['fields'] ?? null);
        $errors = [];
        $clean  = [];

        foreach ($fields as $field) {
            $raw = $input[$field['name']] ?? null;
            if (is_string($raw)) {
                $raw = trim($raw);
            }
            if ($field['required'] && ($raw === null || $raw === '' || $raw === [])) {
                $errors[$field['name']] = 'This field is required.';
                continue;
            }
            if ($raw === null || $raw === '') {
                continue;
            }
            if ($field['type'] === 'email' && !filter_var((string) $raw, FILTER_VALIDATE_EMAIL)) {
                $errors[$field['name']] = 'Please enter a valid email address.';
                continue;
            }
            if ($field['type'] === 'url' && !filter_var((string) $raw, FILTER_VALIDATE_URL)) {
                $errors[$field['name']] = 'Please enter a valid URL.';
                continue;
            }
            $clean[$field['name']] = $raw;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'submission_id' => null];
        }

        $id  = Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE_SUBMISSIONS . ' (id, form_id, data, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            (string) $form['id'],
            (string) json_encode($clean, JSON_UNESCAPED_UNICODE),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            $now,
        ]);

        return ['ok' => true, 'errors' => [], 'submission_id' => $id];
    }

    /** @return array<array<string, mixed>> */
    public function listSubmissions(string $formId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt  = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE_SUBMISSIONS . ' WHERE form_id = ? ORDER BY created_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$formId]);
        return $stmt->fetchAll();
    }

    private function encodeFields(mixed $raw): string
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return '[]';
            }
            $raw = $decoded;
        }
        return (string) json_encode($this->decodeFields($raw), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Normalize a slug input into [a-z0-9-]+ or null. Strict so two forms
     * never end up with subtly-different slugs (`Newsletter` vs `newsletter`).
     */
    private function normalizeSlug(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }
        $clean = preg_replace('/[^a-z0-9]+/', '-', $raw) ?? '';
        $clean = trim($clean, '-');
        return $clean === '' ? null : substr($clean, 0, 120);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  mixed $raw
     * @return array<string, string>
     */
    private function cleanOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $opt) {
            if (is_array($opt)) {
                $v = trim((string) ($opt['value'] ?? ''));
                if ($v !== '') {
                    $out[$v] = trim((string) ($opt['label'] ?? $v));
                }
            } elseif (is_string($opt)) {
                $v = trim($opt);
                if ($v !== '') {
                    $out[$v] = $v;
                }
            }
        }
        return $out;
    }
}
