<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Import\ImportOptions;

/**
 * The import screens.
 *
 * Deliberately thin: every decision — what a file contains, what to write,
 * how to resume — belongs to ImportService. This class picks files, collects
 * options, and renders progress.
 */
class ImportController extends BaseAdminController
{
    /** Where uploaded (or FTP-dropped) export files live. */
    private const UPLOAD_DIR = TYPEDOCK_ROOT . '/storage/import';

    public function index(): void
    {
        // The page tells operators they can drop a file in here over FTP, so
        // the directory has to exist before they go looking for it.
        $this->ensureUploadDir();

        $this->render('pages/import/index.latte', [
            'importers'     => $this->importerChoices(),
            'files'         => $this->availableFiles(),
            'upload_dir'    => self::UPLOAD_DIR,
            'upload_limit'  => $this->uploadLimit(),
            'recent'        => $this->recentImports(),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    /**
     * Accept an export file. The alternative — dropping it into
     * `storage/import/` over FTP — is listed right next to this on the page,
     * because a 30MB WXR against a 8MB upload limit is the single most likely
     * way a shared-hosting migration dies before it starts.
     */
    public function upload(): void
    {
        $file = $_FILES['export_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->redirect('/admin/import', __('Upload failed. The file may be larger than this server allows.'), 'error');
            return;
        }

        $name = $this->safeFilename((string) $file['name']);
        if ($name === null) {
            $this->redirect('/admin/import', __('Only .xml and .xml.gz export files are accepted.'), 'error');
            return;
        }

        if (!$this->ensureUploadDir()) {
            $this->redirect('/admin/import', __('Could not create storage/import. Check directory permissions.'), 'error');
            return;
        }

        if (!move_uploaded_file((string) $file['tmp_name'], self::UPLOAD_DIR . '/' . $name)) {
            $this->redirect('/admin/import', __('Could not save the uploaded file.'), 'error');
            return;
        }

        $this->redirect('/admin/import?file=' . rawurlencode($name), __('Upload complete. Review what it contains below.'));
    }

    /**
     * Dry run. Reads the whole file and reports, writing nothing — the step
     * that turns "I hope this works" into a decision.
     */
    public function scan(): void
    {
        $this->requireImportPermission();

        $importer = (string) ($_POST['importer'] ?? '');
        $file     = $this->resolveFile((string) ($_POST['file'] ?? ''));

        if ($file === null) {
            $this->redirect('/admin/import', __('Choose a file to import.'), 'error');
            return;
        }

        try {
            $scan = \Flight::imports()->scan($importer, $file);
        } catch (\Throwable $e) {
            $this->redirect('/admin/import', $e->getMessage(), 'error');
            return;
        }

        $this->render('pages/import/review.latte', [
            'importer'       => $importer,
            'file'           => basename($file),
            'scan'           => $scan,
            'allow_raw_html' => $this->can('content:unfiltered_html'),
            'authors'        => $this->matchAuthors($scan->authors),
        ]);
    }

    /**
     * Register the run and hand it to the background worker.
     */
    public function start(): void
    {
        $this->requireImportPermission();

        $importer = (string) ($_POST['importer'] ?? '');
        $file     = $this->resolveFile((string) ($_POST['file'] ?? ''));
        if ($file === null) {
            $this->redirect('/admin/import', __('Choose a file to import.'), 'error');
            return;
        }

        $user = \Flight::get('current_user');

        $options = new ImportOptions(
            asDraft: isset($_POST['as_draft']),
            defaultAuthorId: is_array($user) ? (string) $user['id'] : null,
            locale: typedock_default_locale(),
            fetchMedia: isset($_POST['fetch_media']),
            rewriteLinks: isset($_POST['rewrite_links']),
            sourceSiteUrl: ($_POST['source_site_url'] ?? '') !== '' ? (string) $_POST['source_site_url'] : null,
            // Not a checkbox: whether raw HTML may be written is the caller's
            // capability, and the review screen has already said what that
            // means for this file.
            allowRawHtml: $this->can('content:unfiltered_html'),
        );

        try {
            $service  = \Flight::imports();
            $importId = $service->create($importer, $file, $options, is_array($user) ? (string) $user['id'] : null);
            $service->enqueue($importId);
        } catch (\Throwable $e) {
            $this->redirect('/admin/import', $e->getMessage(), 'error');
            return;
        }

        \Flight::redirect('/admin/import/' . $importId);
    }

    public function show(string $id): void
    {
        $import = \Flight::imports()->find($id);
        if ($import === null) {
            $this->redirect('/admin/import', __('That import no longer exists.'), 'error');
            return;
        }

        $this->render('pages/import/progress.latte', [
            'import'        => $import,
            'progress'      => \Flight::imports()->progress($id),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    /**
     * Do a slice of work and report back. The progress page calls this in a
     * loop, which is what moves an import along on hosting with no cron.
     */
    public function tick(string $id): void
    {
        $this->requireImportPermission();

        $tick = \Flight::job_runner()->run();

        \Flight::json([
            'queue'    => $tick,
            'progress' => \Flight::imports()->progress($id),
        ]);
    }

    public function undo(string $id): void
    {
        $this->requireImportPermission();

        $removed = \Flight::imports()->undo($id);

        $this->redirect('/admin/import', __('Removed {n} imported item(s).', ['n' => $removed]));
    }

    /**
     * Old URL → new URL, as a CSV. Kept as a download rather than wired into
     * the redirect plugin so the two features stay independent — plenty of
     * sites handle redirects at the CDN or web server instead.
     */
    public function redirects(string $id): void
    {
        $rows = \Flight::imports()->redirectMap($id);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="redirects-' . $id . '.csv"');
        header('Cache-Control: private, no-store');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['from', 'to']);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }

    // -----------------------------------------------------------------

    private function ensureUploadDir(): bool
    {
        return is_dir(self::UPLOAD_DIR) || @mkdir(self::UPLOAD_DIR, 0775, true);
    }

    private function requireImportPermission(): void
    {
        // Importing writes posts as though an editor had, and can replace
        // existing content wholesale, so it is an admin-level action.
        $user = \Flight::get('current_user');
        if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
            throw new \TypeDock\Exception\ForbiddenException('Importing content requires an administrator.');
        }
    }

    /** @return array<string, string> key => label */
    private function importerChoices(): array
    {
        $choices = [];
        foreach (\Flight::importers()->all() as $key => $importer) {
            $choices[$key] = $importer->label();
        }

        return $choices;
    }

    /**
     * Export files sitting in storage/import, newest first.
     *
     * @return array<int, array{name:string, size:int, modified:int}>
     */
    private function availableFiles(): array
    {
        $files = [];
        foreach (glob(self::UPLOAD_DIR . '/*') ?: [] as $path) {
            if (!is_file($path) || $this->safeFilename(basename($path)) === null) {
                continue;
            }
            $files[] = [
                'name'     => basename($path),
                'size'     => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($files, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Turn a submitted filename back into a path inside the upload directory.
     * Anything that is not a plain file in that directory is refused, so a
     * crafted `file` parameter cannot walk the filesystem.
     */
    private function resolveFile(string $name): ?string
    {
        $name = $this->safeFilename($name);
        if ($name === null) {
            return null;
        }

        $path = self::UPLOAD_DIR . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** Null when the name is unusable or not an accepted export. */
    private function safeFilename(string $name): ?string
    {
        $name = basename(trim($name));
        if ($name === '' || str_starts_with($name, '.')) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]+\.(xml|xml\.gz|gz)$/', $name) !== 1) {
            return null;
        }

        return $name;
    }

    /**
     * Which of the file's authors already have an account here. Shown so the
     * operator knows before starting that posts by unmatched authors will be
     * attributed to them.
     *
     * @param  array<int, array{email:?string, name:string}> $authors
     * @return array<int, array{name:string, email:?string, matched:bool}>
     */
    private function matchAuthors(array $authors): array
    {
        $stmt = \Flight::db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');

        $out = [];
        foreach ($authors as $author) {
            $email = $author['email'] ?? null;
            $found = false;
            if ($email !== null && $email !== '') {
                $stmt->execute([strtolower($email)]);
                $found = $stmt->fetchColumn() !== false;
            }
            $out[] = ['name' => $author['name'], 'email' => $email, 'matched' => $found];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function recentImports(): array
    {
        $stmt = \Flight::db()->query(
            'SELECT id, importer, source_name, status, processed, created_at
               FROM imports ORDER BY created_at DESC LIMIT 10'
        );

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** The smaller of the two limits that actually stops an upload. */
    private function uploadLimit(): string
    {
        $upload = (string) ini_get('upload_max_filesize');
        $post   = (string) ini_get('post_max_size');

        return self::toBytes($upload) <= self::toBytes($post) ? $upload : $post;
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower($value[strlen($value) - 1])) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
