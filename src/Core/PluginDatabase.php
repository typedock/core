<?php
declare(strict_types=1);

namespace TypeDock\Core;

class PluginDatabase
{
    private string $prefix;

    public function __construct(
        private readonly \PDO $pdo,
        string $pluginSlug
    ) {
        $this->prefix = 'plugin_' . str_replace('-', '_', $pluginSlug) . '_';
    }

    private function tableName(string $table): string
    {
        return $this->prefix . ltrim($table, '_');
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
                $clauses[] = $col . ' = ?';
                $params[]  = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if (isset($options['order_by'])) {
            $sql .= ' ORDER BY ' . $options['order_by'];
        }

        $limit = min((int) ($options['limit'] ?? 50), 200);
        $sql .= ' LIMIT ' . $limit;

        if (isset($options['offset'])) {
            $sql .= ' OFFSET ' . (int) $options['offset'];
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
            $data['id'] = \Ramsey\Uuid\Uuid::uuid7()->toString();
        }

        $cols     = array_keys($data);
        $colList  = implode(', ', $cols);
        $valList  = implode(', ', array_fill(0, count($cols), '?'));

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
        $setClauses = [];
        $params     = [];
        foreach ($data as $col => $val) {
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
}
