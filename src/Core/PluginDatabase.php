<?php
declare(strict_types=1);

namespace TypeDock\Core;

class PluginDatabase
{
    /** Identifier safe for direct interpolation into SQL. */
    private const IDENT_REGEX = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    private string $prefix;

    public function __construct(
        private readonly \PDO $pdo,
        string $pluginSlug
    ) {
        // Slug is already validated by PluginLoader, but normalise again so
        // the table prefix can never contain non-identifier characters.
        $normalised   = preg_replace('/[^A-Za-z0-9]/', '_', strtolower($pluginSlug));
        $this->prefix = 'plugin_' . trim((string) $normalised, '_') . '_';
    }

    /**
     * Escape hatch: raw PDO connection. Plugin owns SQL safety from here on
     * — prepared statements strongly recommended. Use when the scoped CRUD
     * methods are insufficient (joins across plugin tables and Core tables,
     * introspection, transactions, etc.).
     */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Prefix calculator for raw queries: `SELECT * FROM {$db->table('items')}`
     * lets plugin authors write joins without re-hardcoding the prefix.
     */
    public function table(string $name): string
    {
        return $this->tableName($name);
    }

    private function tableName(string $table): string
    {
        $table = ltrim($table, '_');
        $this->assertIdent($table, 'table');
        return $this->prefix . $table;
    }

    public function find(string $table, string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->tableName($table) . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param  array<string, mixed> $conditions
     * @param  array<string, mixed> $options
     * @return array<array<string, mixed>>
     */
    public function list(string $table, array $conditions = [], array $options = []): array
    {
        $sql    = 'SELECT * FROM ' . $this->tableName($table);
        $params = [];

        if (!empty($conditions)) {
            $clauses = [];
            foreach ($conditions as $col => $val) {
                $this->assertIdent((string) $col, 'column');
                $clauses[] = $col . ' = ?';
                $params[]  = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if (!empty($options['order_by'])) {
            $sql .= ' ORDER BY ' . $this->compileOrderBy((string) $options['order_by']);
        }

        $limit = min((int) ($options['limit'] ?? 50), 200);
        $sql .= ' LIMIT ' . $limit;

        if (isset($options['offset'])) {
            $sql .= ' OFFSET ' . max(0, (int) $options['offset']);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param  array<string, mixed> $data
     */
    public function insert(string $table, array $data): string
    {
        if (!isset($data['id'])) {
            $data['id'] = typedock_uuid7();
        }

        foreach (array_keys($data) as $col) {
            $this->assertIdent((string) $col, 'column');
        }

        $cols    = array_keys($data);
        $colList = implode(', ', $cols);
        $valList = implode(', ', array_fill(0, count($cols), '?'));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->tableName($table) . " ({$colList}) VALUES ({$valList})"
        );
        $stmt->execute(array_values($data));

        return (string) $data['id'];
    }

    /**
     * @param  array<string, mixed> $data
     */
    public function update(string $table, string $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $setClauses = [];
        $params     = [];
        foreach ($data as $col => $val) {
            $this->assertIdent((string) $col, 'column');
            $setClauses[] = $col . ' = ?';
            $params[]     = $val;
        }
        $params[] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->tableName($table) . ' SET ' . implode(', ', $setClauses) . ' WHERE id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(string $table, string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->tableName($table) . ' WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    public function count(string $table, array $conditions = []): int
    {
        $sql    = 'SELECT COUNT(*) FROM ' . $this->tableName($table);
        $params = [];
        if (!empty($conditions)) {
            $clauses = [];
            foreach ($conditions as $col => $val) {
                $this->assertIdent((string) $col, 'column');
                $clauses[] = $col . ' = ?';
                $params[]  = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Accept "col", "col DESC", or "col1 ASC, col2 DESC". Anything else throws.
     */
    private function compileOrderBy(string $orderBy): string
    {
        $parts = array_map('trim', explode(',', $orderBy));
        $out   = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', $part) ?: [];
            $col    = $tokens[0] ?? '';
            $dir    = strtoupper($tokens[1] ?? 'ASC');
            $this->assertIdent($col, 'column');
            if (!in_array($dir, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException("Invalid order direction: {$dir}");
            }
            if (count($tokens) > 2) {
                throw new \InvalidArgumentException("Invalid order_by segment: {$part}");
            }
            $out[] = $col . ' ' . $dir;
        }
        if ($out === []) {
            throw new \InvalidArgumentException('Empty order_by');
        }
        return implode(', ', $out);
    }

    private function assertIdent(string $name, string $kind): void
    {
        if (preg_match(self::IDENT_REGEX, $name) !== 1) {
            throw new \InvalidArgumentException("Invalid {$kind} identifier: {$name}");
        }
    }
}
