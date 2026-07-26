<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

use TypeDock\Core\Database\ConnectionFactory;
use TypeDock\Core\Database\LibsqlPdo;

typedock_load_config(TYPEDOCK_ROOT);

/** ---- Argument parsing ---- */
$argv = $_SERVER['argv'] ?? [];
array_shift($argv);

$dryRun    = false;
$overwrite = false;
$asDraft   = false;
$importer  = null;
$path      = null;

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--overwrite') {
        $overwrite = true;
    } elseif ($arg === '--as-draft') {
        $asDraft = true;
    } elseif (str_starts_with($arg, '--importer=')) {
        $importer = substr($arg, 11);
    } elseif ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } else {
        $path = $arg;
    }
}

if ($path === null) {
    printUsage();
    exit(2);
}
if (!is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(1);
}

// Content import from another CMS goes through the Import subsystem; without
// --importer this stays the counterpart of cli/export.php (TypeDock's own
// JSON dumps of options, menus, slots and redirects).
if ($importer !== null) {
    exit(runContentImport($importer, $path, $dryRun, $asDraft));
}

$json = json_decode((string) file_get_contents($path), true);
if (!is_array($json)) {
    fwrite(STDERR, "Invalid JSON: {$path}\n");
    exit(1);
}

/** ---- DB connection ---- */
$db  = require TYPEDOCK_ROOT . '/config/database.php';
$pdo = ConnectionFactory::create($db, TYPEDOCK_ROOT);

/** ---- Detect kind ---- */
$basename = strtolower(basename($path));
$kind = match (true) {
    str_contains($basename, 'site_option') => 'site_options',
    str_contains($basename, 'menu')        => 'menus',
    str_contains($basename, 'slot')        => 'slot_placements',
    str_contains($basename, 'redirect')    => 'redirects',
    default                                => detectKindFromShape($json),
};

if ($kind === null) {
    fwrite(STDERR, "Could not detect import kind from filename or content. Expected site_options/menus/slots/redirects.\n");
    exit(1);
}

echo "TypeDock Import CLI\n";
echo "Source: {$path}\n";
echo "Kind:   {$kind}\n";
echo "Mode:   " . ($dryRun ? 'DRY RUN' : ($overwrite ? 'OVERWRITE' : 'SKIP-IF-EXISTS')) . "\n";
echo str_repeat('-', 60) . "\n";

$counts = ['imported' => 0, 'skipped' => 0, 'updated' => 0, 'errored' => 0];
$useTransaction = !$dryRun && !($pdo instanceof LibsqlPdo);

try {
    if ($useTransaction) {
        $pdo->beginTransaction();
    }

    switch ($kind) {
        case 'site_options':
            importRows($pdo, 'site_options', 'key_name', $json, $overwrite, $dryRun, $counts);
            break;

        case 'redirects':
            importRows($pdo, 'redirects', 'id', $json, $overwrite, $dryRun, $counts);
            break;

        case 'slot_placements':
            importRows($pdo, 'slot_placements', 'id', $json, $overwrite, $dryRun, $counts);
            break;

        case 'menus':
            // Each entry: menu row + nested 'items' (menu_items).
            foreach ($json as $menu) {
                $items = $menu['items'] ?? [];
                unset($menu['items']);

                $res = upsertRow($pdo, 'menus', 'id', $menu, $overwrite, $dryRun);
                tally($counts, $res);
                if ($res === 'errored') {
                    continue;
                }

                foreach ($items as $item) {
                    $r = upsertRow($pdo, 'menu_items', 'id', $item, $overwrite, $dryRun);
                    tally($counts, $r);
                }
            }
            break;
    }

    if ($useTransaction) {
        $pdo->commit();
    }
} catch (\Throwable $e) {
    if ($useTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Fatal: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nResult:\n";
echo "  Imported (new):  {$counts['imported']}\n";
echo "  Updated:         {$counts['updated']}\n";
echo "  Skipped:         {$counts['skipped']}\n";
echo "  Errored:         {$counts['errored']}\n";
if ($dryRun) {
    echo "\n(dry run — no changes written)\n";
}
exit($counts['errored'] > 0 ? 1 : 0);

/** ---- Helpers ---- */

function printUsage(): void
{
    echo "Usage: php cli/import.php <path-to-export.json> [--dry-run] [--overwrite]\n";
    echo "  Detects kind (site_options|menus|slots|redirects) by filename.\n";
    echo "\n";
    echo "       php cli/import.php --importer=<key> <export-file> [--dry-run] [--as-draft]\n";
    echo "  Import content from another CMS, e.g. --importer=wordpress export.xml\n";
    echo "  --dry-run   Report what the file contains without writing anything.\n";
    echo "  --as-draft  Import everything as a draft instead of honouring the source status.\n";
}

/**
 * Import content through a registered ImporterInterface.
 *
 * Boots services and plugins the same way a web request does — importers ship
 * as plugins, so nothing is registered without the plugin loader.
 */
function runContentImport(string $importerKey, string $path, bool $dryRun, bool $asDraft): int
{
    (new \TypeDock\Core\ServiceProvider())->register();
    (new \TypeDock\Core\PluginLoader())->load();

    $service = new \TypeDock\Import\ImportService(\Flight::db(), \Flight::importers());

    echo "TypeDock Content Import\n";
    echo "Source:   {$path}\n";
    echo "Importer: {$importerKey}\n";
    echo str_repeat('-', 60) . "\n";

    try {
        $scan = $service->scan($importerKey, $path);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }

    foreach ($scan->counts as $label => $count) {
        printf("  %-12s %d\n", $label, $count);
    }
    if ($scan->unmappedNodes > 0) {
        echo "\n  {$scan->unmappedNodes} element(s) cannot be turned into blocks and will be kept as raw HTML.\n";
    }
    foreach (array_slice($scan->warnings, 0, 20) as $warning) {
        echo "  ! {$warning}\n";
    }
    if (count($scan->warnings) > 20) {
        echo '  ! … and ' . (count($scan->warnings) - 20) . " more\n";
    }

    if ($dryRun) {
        echo "\n(dry run — nothing written)\n";
        return 0;
    }

    echo "\nImages are left pointing at the source site: fetching them into the media\n";
    echo "library is not implemented yet. Keep the original site online for now.\n\n";

    $options  = new \TypeDock\Import\ImportOptions(asDraft: $asDraft);
    $importId = $service->create($importerKey, $path, $options);

    try {
        do {
            $result = $service->advance($importId);
            printf(
                "  processed=%d created=%d updated=%d failed=%d\n",
                $result['processed'],
                $result['created'],
                $result['updated'],
                $result['failed']
            );
        } while (!$result['done']);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }

    echo "\nImport {$importId} complete.\n";
    echo "Undo with: DELETE FROM posts WHERE import_batch_id = '{$importId}';\n";

    return $result['failed'] > 0 ? 1 : 0;
}

function detectKindFromShape(array $rows): ?string
{
    if ($rows === []) {
        return null;
    }
    $first = $rows[0] ?? null;
    if (!is_array($first)) {
        return null;
    }
    if (array_key_exists('key_name', $first)) {
        return 'site_options';
    }
    if (array_key_exists('items', $first) || array_key_exists('menu_id', $first)) {
        return 'menus';
    }
    if (array_key_exists('slot_name', $first)) {
        return 'slot_placements';
    }
    if (array_key_exists('source_path', $first) || array_key_exists('target_path', $first) || array_key_exists('from_path', $first)) {
        return 'redirects';
    }
    return null;
}

/**
 * Insert/skip/update a single row by primary key.
 * Returns 'imported' | 'updated' | 'skipped' | 'errored'.
 */
function upsertRow(PDO $pdo, string $table, string $pk, array $row, bool $overwrite, bool $dryRun): string
{
    if (!array_key_exists($pk, $row) || $row[$pk] === null || $row[$pk] === '') {
        fwrite(STDERR, "  ! row in {$table} missing primary key '{$pk}', skipping\n");
        return 'errored';
    }

    try {
        $check = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$pk} = ? LIMIT 1");
        $check->execute([$row[$pk]]);
        $exists = (bool) $check->fetchColumn();

        if ($exists && !$overwrite) {
            return 'skipped';
        }

        if ($dryRun) {
            return $exists ? 'updated' : 'imported';
        }

        if ($exists) {
            $sets   = [];
            $values = [];
            foreach ($row as $col => $val) {
                if ($col === $pk) {
                    continue;
                }
                $sets[]   = "{$col} = ?";
                $values[] = is_scalar($val) || $val === null ? $val : json_encode($val);
            }
            $values[] = $row[$pk];
            $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pk} = ?";
            $pdo->prepare($sql)->execute($values);
            return 'updated';
        }

        $cols   = array_keys($row);
        $place  = implode(', ', array_fill(0, count($cols), '?'));
        $colSql = implode(', ', $cols);
        $values = array_map(
            static fn($v) => is_scalar($v) || $v === null ? $v : json_encode($v),
            array_values($row)
        );
        $pdo->prepare("INSERT INTO {$table} ({$colSql}) VALUES ({$place})")->execute($values);
        return 'imported';
    } catch (\Throwable $e) {
        fwrite(STDERR, "  ! {$table}[{$row[$pk]}]: " . $e->getMessage() . "\n");
        return 'errored';
    }
}

function importRows(PDO $pdo, string $table, string $pk, array $rows, bool $overwrite, bool $dryRun, array &$counts): void
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            $counts['errored']++;
            continue;
        }
        tally($counts, upsertRow($pdo, $table, $pk, $row, $overwrite, $dryRun));
    }
}

function tally(array &$counts, string $result): void
{
    $counts[$result] = ($counts[$result] ?? 0) + 1;
}
