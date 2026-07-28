<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class AgentUpdateContext
{
    public function __construct(private readonly PreflightReport $report) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'product' => 'TypeDock',
            'current_version' => (string) \config('app.version', defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.8.0'),
            'update_channel' => (string) \config('update.channel', 'stable'),
            'update_url' => $this->channelUrl(),
            'installation' => [
                'mode' => $this->report->profile->mode,
                'root' => $this->report->profile->root,
                'public_dir' => $this->report->profile->publicDir,
                'split_public' => $this->report->profile->isSplitPublic(),
            ],
            'package' => [
                'schema_version' => $this->report->manifest->schemaVersion,
                'version' => $this->report->manifest->version,
                'managed_paths' => $this->report->manifest->managedPaths,
                'bundled_themes' => $this->report->manifest->bundledThemes,
                'bundled_plugins' => $this->report->manifest->bundledPlugins,
            ],
            'preflight' => array_map(
                static fn(PreflightIssue $issue): array => [
                    'severity' => $issue->severity,
                    'label' => $issue->label,
                    'message' => $issue->message,
                ],
                $this->report->issues
            ),
            'ownership' => $this->report->ownership,
            'agent_policy' => [
                'core_can_apply_files' => $this->report->profile->isZipManaged(),
                'preserve' => ['config.php', 'storage/', 'public/uploads/', 'themes/<user-owned>/', 'plugins/<user-owned>/'],
                'regenerate' => ['public/themes/', 'public/plugins/'],
                'must_backup_database_before_migrations' => true,
                'must_verify_release_signature' => true,
                'must_not_overwrite_user_owned_extensions' => true,
            ],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function prompt(): string
    {
        $context = $this->toJson();

        return <<<PROMPT
You are updating a TypeDock CMS installation with a coding agent.

Goal:
- Update TypeDock Core to the latest compatible release.
- Preserve user-owned themes, user-owned plugins, uploads, storage, and config.php.
- Treat bundled themes/plugins as Core-owned only when the package manifest says so.
- If bundled files were modified locally, back them up and explain the overwrite before applying.

Required workflow:
1. Read the JSON context below.
2. Verify the target release artifact and signature using TypeDock release metadata.
3. Create a database backup using the correct host/deployment tool.
4. Put the site in maintenance mode if changing live files.
5. Apply Core managed paths from the release package.
6. Do not overwrite user-owned themes/plugins.
7. Run TypeDock migrations.
8. Republish assets with `php cli/assets-publish.php`.
9. Run smoke checks for `/`, `/admin/login`, `/sitemap.xml`, and `/feed`.
10. If anything fails, restore files and database from backup.
11. Report exactly what changed and where backups were written.

TypeDock agent update context:
```json
{$context}
```
PROMPT;
    }

    private function channelUrl(): string
    {
        $channels = \config('update.channels', []);
        if (!is_array($channels)) {
            return '';
        }
        return (string) ($channels[(string) \config('update.channel', 'stable')] ?? '');
    }
}
