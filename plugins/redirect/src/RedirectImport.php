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
    private const STATUS_CODES = [301, 302, 307, 308];

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

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * @param  string $path     Readable file on disk.
     * @param  string $filename Name as uploaded — decides CSV vs JSON.
     * @return array{created:int, updated:int, skipped:int, errors:array<int, string>}
     */
    public function importFile(string $path, string $filename): array
    {
        $rows = str_ends_with(strtolower($filename), '.json')
            ? $this->readJson($path)
            : $this->readCsv($path);

        return $this->write($rows);
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
        $pending = 0;
        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Batched rather than per-row: a few thousand rules committed one at a
        // time is slow enough on shared hosting to hit the request timeout
        // half-loaded.
        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                try {
                    [$source, $target, $status] = $this->validate($row);
                } catch (\InvalidArgumentException $e) {
                    $skipped++;
                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = sprintf('Line %d: %s', $row['line'], $e->getMessage());
                    }
                    continue;
                }

                if (isset($known[$source])) {
                    $update->execute([$target, $status, $known[$source]]);
                    $updated++;
                } else {
                    $id = typedock_uuid7();
                    $insert->execute([$id, $source, $target, $status, $now]);
                    // Recorded so the same source appearing twice in one file
                    // updates the row this run just created instead of
                    // inserting a second one.
                    $known[$source] = $id;
                    $created++;
                }

                if (++$pending >= self::COMMIT_EVERY) {
                    $this->pdo->commit();
                    $this->pdo->beginTransaction();
                    $pending = 0;
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
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
     * @param  array{line:int, source:string, target:string, status:string} $row
     * @return array{0: string, 1: string, 2: int}
     */
    private function validate(array $row): array
    {
        $source = trim($row['source']);
        $target = trim($row['target']);

        if ($source === '' || $target === '') {
            throw new \InvalidArgumentException('source and target are both required.');
        }

        $source = $this->normaliseSource($source);
        $target = $this->normaliseTarget($target);

        if ($source === $target) {
            throw new \InvalidArgumentException('source and target are the same, which would loop.');
        }

        $status = trim($row['status']);
        if ($status === '') {
            $status = '301';
        }
        if (!in_array((int) $status, self::STATUS_CODES, true) || !ctype_digit($status)) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not one of %s.', $status, implode(', ', self::STATUS_CODES))
            );
        }

        return [$source, $target, (int) $status];
    }

    /**
     * A source is either a request path or a `~`-prefixed pattern.
     *
     * Absolute URLs are reduced to their path because a redirect list written
     * from an old site is full of them, and a rule stored with a scheme and
     * host would never match anything — RedirectMiddleware compares paths.
     */
    private function normaliseSource(string $source): string
    {
        if (str_starts_with($source, '~')) {
            // RegexResolver runs patterns under `@preg_match`, so a broken one
            // is stored happily and then silently never fires. Reject it here,
            // where there is still someone to tell.
            if (@preg_match('#' . str_replace('#', '\\#', substr($source, 1)) . '#', '') === false) {
                throw new \InvalidArgumentException('the regular expression does not compile.');
            }

            return $source;
        }

        if (preg_match('#^https?://#i', $source) === 1) {
            $parts = parse_url($source);
            $path  = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
            $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';

            // `/?p=123` style permalinks are identified by their query alone;
            // emitting the bare path would redirect the site's home page.
            $source = $path === '' || $path === '/'
                ? ($query !== '' ? '/?' . $query : '')
                : $path . ($query !== '' ? '?' . $query : '');

            if ($source === '') {
                throw new \InvalidArgumentException('the source URL has no path to match on.');
            }
        }

        return '/' . ltrim($source, '/');
    }

    /** Targets may be absolute URLs; anything else is a path on this site. */
    private function normaliseTarget(string $target): string
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $target) === 1
            ? $target
            : '/' . ltrim($target, '/');
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
     * @return \Generator<int, array{line:int, source:string, target:string, status:string}>
     */
    private function readJson(string $path): \Generator
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('Expected a JSON array of {"from": "…", "to": "…"} objects.');
        }

        foreach ($decoded as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            yield [
                'line'   => $index + 1,
                'source' => $this->firstKey($entry, self::SOURCE_KEYS),
                'target' => $this->firstKey($entry, self::TARGET_KEYS),
                'status' => $this->firstKey($entry, self::STATUS_KEYS),
            ];
        }
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
}
