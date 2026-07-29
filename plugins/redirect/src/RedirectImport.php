<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Redirect;

/**
 * Bulk-load redirect rules from a CSV or JSON file.
 *
 * Migrating a site produces hundreds of them at once — TypeDock's own importer
 * hands out exactly this list as `redirects-<id>.csv` — and adding those one
 * form submission at a time is not a realistic thing to ask of anyone.
 *
 * Re-importing the same file is a no-op rather than a pile of duplicates: a
 * source path is looked up before it is written, so the file describes the
 * desired state instead of a set of appends. (`redirects.source_path` carries
 * no unique index, and adding one would have to cope with the 2000-character
 * column exceeding MySQL's key length, so the check lives here.)
 */
final class RedirectImport
{
    /** Column names accepted in a CSV header row or as JSON object keys. */
    private const SOURCE_KEYS = ['from', 'source', 'source_path'];
    private const TARGET_KEYS = ['to', 'target', 'target_url'];
    private const STATUS_KEYS = ['status', 'status_code'];

    /** Report the first few bad rows; a wall of errors helps nobody. */
    private const MAX_ERRORS = 10;

    /**
     * Commit every N rows rather than once at the end.
     *
     * The remote libSQL driver buffers a whole transaction into a single HTTP
     * request, so one open transaction over a 10,000-row file would build one
     * enormous payload. Re-running a file converges on the same rules, so a
     * run that dies halfway is fixed by uploading it again — which makes a
     * bounded batch the better trade than one all-or-nothing transaction.
     */
    private const COMMIT_EVERY = 500;

    /** Shared-host friendly hard limits: this feature is for redirects, not ETL. */
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 5000;

    private readonly RedirectRuleValidator $validator;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->validator = new RedirectRuleValidator();
    }

    /**
     * @param  string $path     Readable file on disk.
     * @param  string $filename Name as uploaded — decides CSV vs JSON.
     * @return array{created:int, updated:int, skipped:int, errors:array<int, string>}
     */
    public function importFile(string $path, string $filename): array
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Redirect files may not exceed 2 MB.');
        }

        if (str_ends_with(strtolower($filename), '.json')) {
            $rows = $this->readJson($path);
            $this->assertRowCount(count($rows));
            return $this->write($rows);
        }

        // Preflight the row count before the first write. Otherwise a file
        // above the limit would commit several 500-row batches and then become
        // impossible to finish on retry.
        $count = 0;
        foreach ($this->readCsv($path) as $_row) {
            $this->assertRowCount(++$count);
        }

        return $this->write($this->readCsv($path));
    }

    /**
     * @param  iterable<int, array{line:int, source:string, target:string, status:string}> $rows
     * @return array{created:int, updated:int, skipped:int, errors:array<int, string>}
     */
    private function write(iterable $rows): array
    {
        // Every read happens before the first transaction opens. The remote
        // libSQL driver refuses a SELECT inside one — it buffers writes and
        // ships them as a single atomic batch, so there is nothing to read
        // back from — and a per-row lookup would be a network round trip per
        // rule besides. One scan of a table this size is cheaper anyway.
        $known = $this->loadSourcePaths();

        $update = $this->pdo->prepare('UPDATE redirects SET target_url = ?, status_code = ? WHERE id = ?');
        $insert = $this->pdo->prepare(
            'INSERT INTO redirects (id, source_path, target_url, status_code, created_at) VALUES (?, ?, ?, ?, ?)'
        );

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];
        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $batch   = [];
        $seenSources = array_fill_keys(array_keys($known), true);
        $regexCount = count(array_filter(
            array_keys($known),
            static fn(string $source): bool => str_starts_with($source, '~'),
        ));

        foreach ($rows as $row) {
            try {
                $rule = $this->validator->validate(
                    $row['source'],
                    $row['target'],
                    $row['status'],
                );
                $source = $rule[0];
                if (str_starts_with($source, '~') && !isset($seenSources[$source])) {
                    if ($regexCount >= RegexPattern::MAX_RULES) {
                        throw new \InvalidArgumentException(sprintf(
                            'no more than %d regular-expression rules may be active.',
                            RegexPattern::MAX_RULES,
                        ));
                    }
                    $regexCount++;
                }
                $seenSources[$source] = true;
                $batch[] = $rule;
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = sprintf('Line %d: %s', $row['line'], $e->getMessage());
                }
                continue;
            }

            if (count($batch) >= self::COMMIT_EVERY) {
                [$added, $changed] = $this->writeBatch($batch, $known, $insert, $update, $now);
                $created += $added;
                $updated += $changed;
                $batch = [];
            }
        }

        if ($batch !== []) {
            [$added, $changed] = $this->writeBatch($batch, $known, $insert, $update, $now);
            $created += $added;
            $updated += $changed;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * File parsing and rule validation happen before this method opens the
     * transaction. Only the bounded write batch holds a DB transaction.
     *
     * @param array<int, array{0:string,1:string,2:int}> $batch
     * @param array<string, string> $known
     * @return array{0:int,1:int} created, updated
     */
    private function writeBatch(
        array $batch,
        array &$known,
        \PDOStatement $insert,
        \PDOStatement $update,
        string $now,
    ): array {
        $created = 0;
        $updated = 0;
        $this->pdo->beginTransaction();

        try {
            foreach ($batch as [$source, $target, $status]) {
                if (isset($known[$source])) {
                    $update->execute([$target, $status, $known[$source]]);
                    $updated++;
                    continue;
                }

                $id = typedock_uuid7();
                $insert->execute([$id, $source, $target, $status, $now]);
                $known[$source] = $id;
                $created++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [$created, $updated];
    }

    /**
     * Existing source path => row id.
     *
     * @return array<string, string>
     */
    private function loadSourcePaths(): array
    {
        $stmt = $this->pdo->query('SELECT id, source_path FROM redirects');
        if ($stmt === false) {
            return [];
        }

        $known = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $known[(string) $row['source_path']] = (string) $row['id'];
        }

        return $known;
    }

    /**
     * @return \Generator<int, array{line:int, source:string, target:string, status:string}>
     */
    private function readCsv(string $path): \Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        try {
            $line    = 0;
            $columns = null;

            // Empty `escape`: a backslash is not an escape character in CSV,
            // and PHP's non-standard default would eat the ones in a regex
            // source like `~^/old/(\d+)$`.
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if (!is_array($cells) || $this->isBlank($cells)) {
                    continue;
                }

                // Excel writes a BOM ahead of the first cell, which would
                // otherwise stop the header from being recognised and turn it
                // into a bogus rule.
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]) ?? $cells[0];

                if ($columns === null) {
                    $columns = $this->headerColumns($cells);
                    if ($columns !== null) {
                        continue;
                    }
                    // No header: assume the column order this plugin exports.
                    $columns = ['source' => 0, 'target' => 1, 'status' => 2];
                }

                yield [
                    'line'   => $line,
                    'source' => (string) ($cells[$columns['source']] ?? ''),
                    'target' => (string) ($cells[$columns['target']] ?? ''),
                    'status' => (string) ($columns['status'] !== null ? ($cells[$columns['status']] ?? '') : ''),
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Column positions when the first row names them, null when it does not.
     *
     * @param  array<int, string|null> $cells
     * @return array{source:int, target:int, status:?int}|null
     */
    private function headerColumns(array $cells): ?array
    {
        $source = null;
        $target = null;
        $status = null;

        foreach ($cells as $index => $cell) {
            $name = strtolower(trim((string) $cell));
            if ($source === null && in_array($name, self::SOURCE_KEYS, true)) {
                $source = $index;
            } elseif ($target === null && in_array($name, self::TARGET_KEYS, true)) {
                $target = $index;
            } elseif ($status === null && in_array($name, self::STATUS_KEYS, true)) {
                $status = $index;
            }
        }

        return $source !== null && $target !== null
            ? ['source' => $source, 'target' => $target, 'status' => $status]
            : null;
    }

    /**
     * @return array<int, array{line:int, source:string, target:string, status:string}>
     */
    private function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('Expected a JSON array of {"from": "…", "to": "…"} objects.');
        }

        $rows = [];
        foreach ($decoded as $index => $entry) {
            if (!is_array($entry)) {
                $entry = [];
            }

            $rows[] = [
                'line'   => $index + 1,
                'source' => $this->firstKey($entry, self::SOURCE_KEYS),
                'target' => $this->firstKey($entry, self::TARGET_KEYS),
                'status' => $this->firstKey($entry, self::STATUS_KEYS),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string>   $keys
     */
    private function firstKey(array $entry, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($entry[$key]) && is_scalar($entry[$key])) {
                return (string) $entry[$key];
            }
        }

        return '';
    }

    /** @param array<int, string|null> $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function assertRowCount(int $count): void
    {
        if ($count > self::MAX_ROWS) {
            throw new \RuntimeException(
                sprintf('Redirect files may not contain more than %d rules.', self::MAX_ROWS)
            );
        }
    }
}
