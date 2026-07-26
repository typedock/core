<?php
declare(strict_types=1);

namespace TypeDock\Import;

use TypeDock\Content\TiptapMarkdownRenderer;
use TypeDock\Core\Queue\Job;
use TypeDock\Core\Queue\JobHandler;
use TypeDock\Core\Queue\JobQueue;
use TypeDock\Http\UrlGuard;
use TypeDock\Media\MediaService;

/**
 * Downloads one image reserved by the importer into the media library.
 *
 * One job per image is the right granularity here and nowhere else in the
 * import: this is the only part that touches the network, so it is the only
 * part that fails for reasons worth retrying.
 */
final class ImportMediaJob implements JobHandler
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 20;

    /**
     * Breathing room between fetches. A migration pulls every image a site
     * ever published, from a server that is usually the smallest box its
     * owner could rent — and running two workers doubles the rate, so the
     * politeness has to live here rather than in the scheduler.
     */
    private const DELAY_MICROSECONDS = 200_000;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly MediaService $media,
    ) {
    }

    public function handle(Job $job): void
    {
        $mediaId = (string) ($job->payload['media_id'] ?? '');
        $row     = $mediaId !== '' ? $this->media->find($mediaId) : null;

        // Already fetched (or already given up on): at-least-once delivery
        // means this is a normal outcome, not an error.
        if ($row === null || (string) $row['status'] !== 'pending') {
            return;
        }

        $sourceUrl = (string) $row['source_url'];

        try {
            $tmpFile = $this->download($sourceUrl);
            try {
                $this->media->fulfil($mediaId, $tmpFile);
            } finally {
                @unlink($tmpFile);
            }
        } catch (\Throwable $e) {
            if ($job->attempts < JobQueue::MAX_ATTEMPTS) {
                throw $e;   // the queue backs off and tries again
            }

            // Out of attempts. Rather than leave the body pointing at a path
            // that will 404 forever, put this one image back to the URL it
            // came from: a hotlinked image beats a broken one, and the failed
            // row keeps the source URL for a manual replacement later.
            $this->media->markFailed($mediaId);
            $this->revertToSourceUrl($mediaId, $sourceUrl);

            return;
        }

        usleep(self::DELAY_MICROSECONDS);
    }

    /**
     * Fetch to a temp file, re-validating every redirect hop and pinning the
     * IP that was validated.
     *
     * Redirects are followed by hand precisely because curl's own
     * FOLLOWLOCATION would resolve and connect to whatever the next Location
     * header names, with none of these checks applied — a public URL that
     * 302s to 169.254.169.254 is the classic way past an SSRF guard.
     */
    private function download(string $url): string
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Importing images requires the curl extension.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'td-media-');
        if ($tmpFile === false) {
            throw new \RuntimeException('Could not create a temporary file for the download.');
        }

        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = UrlGuard::inspect($current);

            $handle = fopen($tmpFile, 'wb');
            if ($handle === false) {
                @unlink($tmpFile);
                throw new \RuntimeException('Could not open the temporary file for writing.');
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $target['url'],
                CURLOPT_RESOLVE        => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_FILE           => $handle,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'TypeDockBot/1.0 (+https://typedock.io)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NOPROGRESS     => false,
                // Aborts the transfer the moment either the advertised or the
                // actual size crosses the cap, so a "zip bomb but for images"
                // cannot fill the disk.
                CURLOPT_PROGRESSFUNCTION => static fn(
                    $resource,
                    int $expected,
                    int $received,
                    int $toUpload,
                    int $uploaded
                ): int => ($expected > self::MAX_BYTES || $received > self::MAX_BYTES) ? 1 : 0,
            ]);

            curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            fclose($handle);

            if ($errno !== 0) {
                @unlink($tmpFile);
                throw new \RuntimeException("Download failed: {$error}");
            }

            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $current = $location;
                continue;
            }

            if ($status !== 200) {
                @unlink($tmpFile);
                throw new \RuntimeException("Download failed: HTTP {$status}");
            }

            if ((int) filesize($tmpFile) === 0) {
                @unlink($tmpFile);
                throw new \RuntimeException('Download produced an empty file.');
            }

            return $tmpFile;
        }

        @unlink($tmpFile);
        throw new \RuntimeException('Too many redirects.');
    }

    /**
     * Restore the original URL on every image node referencing this media row.
     *
     * This is the one place an imported body is rewritten, and it is bounded
     * to the handful of posts that used an image we could not fetch.
     */
    private function revertToSourceUrl(string $mediaId, string $sourceUrl): void
    {
        $stmt = $this->pdo->prepare('SELECT id, body FROM posts WHERE body LIKE ?');
        $stmt->execute(['%' . $mediaId . '%']);

        $update = $this->pdo->prepare('UPDATE posts SET body = ?, body_markdown = ? WHERE id = ?');

        foreach ($stmt->fetchAll() as $row) {
            $doc = json_decode((string) $row['body'], true);
            if (!is_array($doc) || !isset($doc['content']) || !is_array($doc['content'])) {
                continue;
            }

            $changed = false;
            $doc['content'] = self::restoreSource($doc['content'], $mediaId, $sourceUrl, $changed);
            if (!$changed) {
                continue;
            }

            $body = json_encode($doc);
            $update->execute([$body, TiptapMarkdownRenderer::render($body) ?: null, $row['id']]);
        }
    }

    /**
     * @param  array<int, mixed> $nodes
     * @return array<int, mixed>
     */
    private static function restoreSource(array $nodes, string $mediaId, string $sourceUrl, bool &$changed): array
    {
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['type'] ?? '') === 'image' && ($node['attrs']['mediaId'] ?? '') === $mediaId) {
                $nodes[$index]['attrs']['src'] = $sourceUrl;
                unset($nodes[$index]['attrs']['mediaId']);
                $changed = true;
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $nodes[$index]['content'] = self::restoreSource($node['content'], $mediaId, $sourceUrl, $changed);
            }
        }

        return $nodes;
    }
}
