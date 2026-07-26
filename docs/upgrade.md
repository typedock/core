# TypeDock Upgrade Guide

TypeDock does not try to rewrite itself from inside the CMS. Instead, it
provides an upgrade preflight and a machine-readable update context so a
human operator, deployment system, or coding agent can apply the release
package while preserving local customizations.

This keeps the update path portable across shared hosting, VPS, Docker,
Git checkouts, split `public_html` layouts, and sites with custom themes
or plugins.

## The Short Version

1. Back up the database and the TypeDock files.
2. Open `/admin/system/update` or run `php cli/upgrade.php --check`.
3. Review ownership warnings for themes and plugins.
4. Download the target TypeDock release package.
5. Verify the release signature/checksum from the official release notes.
6. Replace Core-managed files from the package.
7. Keep `config.php`, `storage/`, uploads, and user-owned themes/plugins.
8. Run `php cli/migrate.php`.
9. Run `php cli/assets-publish.php`.
10. Smoke test the site and admin.

If you use a coding agent, give it the prompt from `/admin/system/update`
or:

```bash
php cli/upgrade.php --agent-prompt
```

## What TypeDock Helps With

TypeDock Core provides:

- A package manifest: `typedock-package.json`
- Installation mode detection: `zip`, `source`, or `container`
- Theme/plugin ownership checks
- Preflight warnings for writable paths, database type, and public layout
- Maintenance mode primitive: `storage/.maintenance`
- Agent handoff context:

```bash
php cli/upgrade.php --agent-context
php cli/upgrade.php --agent-prompt
```

TypeDock Core does **not** automatically replace its own files. The actual
file replacement is done by you, your hosting panel, your deployment
system, or your coding agent.

## Files to Preserve

Never overwrite these during a Core upgrade:

| Path | Why |
|------|-----|
| `config.php` | Site secrets and environment settings |
| `storage/` | Cache, logs, sessions, backups, SQLite DB |
| `public/uploads/` | Uploaded media |
| `themes/<user-owned>/` | Custom or third-party themes |
| `plugins/<user-owned>/` | Custom or third-party plugins |
| `.env` | Legacy compatibility, if present |

`public/themes/` and `public/plugins/` are generated publish output.
They can be regenerated with:

```bash
php cli/assets-publish.php
```

## Files Usually Replaced by Core

The release package manifest lists the Core-managed paths. Typical
managed paths include:

```text
vendor/
src/
migrations/
cli/
admin/
public/admin/dist/
composer.json
composer.lock
LICENSE
README.md
```

Bundled themes and plugins are handled separately because users often
customize them by mistake.

## Theme and Plugin Ownership

The updater preflight classifies theme and plugin directories:

| Status | Meaning | What to do |
|--------|---------|------------|
| `clean` | Bundled by TypeDock and unchanged | Safe to replace from the release |
| `modified` | Bundled by TypeDock but locally changed | Back it up, inspect the diff, then decide |
| `managed-untracked` | Bundled by TypeDock, but this install lacks package hashes | Treat as modified; back it up first |
| `user-owned` | Not owned by TypeDock Core | Do not overwrite |
| `collision` | A user-owned slug conflicts with a new bundled slug | Stop and resolve manually |

Best practice: do not edit bundled themes like `default` or `kinari`
directly. Copy them to a new slug and customize the copy.

## Shared Hosting Upgrade

Use this flow when the site was installed from the shared-hosting zip.

1. Put the site in maintenance mode by creating `storage/.maintenance`
   if you are doing the replacement manually.
2. Back up the database from the hosting panel. For SQLite, copy the
   configured SQLite file under `storage/`.
3. Back up the current TypeDock files, especially bundled themes/plugins
   reported as `modified` or `managed-untracked`.
4. Upload the new release package to a temporary folder.
5. Replace only Core-managed paths.
6. Preserve `config.php`, `storage/`, `public/uploads/`, and user-owned
   `themes/` / `plugins/`.
7. Run:

```bash
php cli/migrate.php
php cli/assets-publish.php
php cli/cache-clear.php
```

If you do not have shell access, use your hosting panel's PHP command
runner if available. Otherwise, upload the files first and visit the
admin area to confirm whether migrations are required.

8. Remove `storage/.maintenance`.
9. Check `/`, `/admin/login`, `/sitemap.xml`, and `/feed`.

## Git or Composer Checkout Upgrade

If the preflight says the install mode is `source`, update it through
your normal source-control workflow:

```bash
git fetch --tags
git checkout <target-tag>
composer install --no-dev --optimize-autoloader
php cli/migrate.php
php cli/assets-publish.php
php cli/cache-clear.php
```

Review local theme/plugin changes before switching tags. A coding agent
can use `php cli/upgrade.php --agent-context` to understand which
directories are Core-owned and which are user-owned.

## Docker Upgrade

If the preflight says the install mode is `container`, do not replace
files inside the running container. Build or pull a new image, recreate
the container, then run migrations against the persistent database.

Typical flow:

```bash
docker compose pull
docker compose up -d
docker compose exec app php cli/migrate.php
docker compose exec app php cli/assets-publish.php
```

Use the exact service name from your deployment.

## Using a Coding Agent

TypeDock is designed to make upgrade work legible to a coding agent.
Give the agent:

```bash
php cli/upgrade.php --agent-prompt
```

or copy the prompt from `/admin/system/update`.

The agent must:

- Verify the release artifact signature/checksum.
- Back up files and the database before changing anything.
- Preserve user-owned themes and plugins.
- Back up and explain any modified bundled theme/plugin before replacing it.
- Run migrations and asset publishing.
- Smoke test the site.
- Restore from backup if any step fails.

The agent should report exactly what changed, what was preserved, and
where backups were written.

## Rollback

Rollback is environment-specific:

- Restore the database backup.
- Restore the previous Core-managed files.
- Restore any bundled theme/plugin backup that was overwritten.
- Run `php cli/assets-publish.php`.
- Run `php cli/cache-clear.php`.
- Remove `storage/.maintenance`.

After rollback, check `/`, `/admin/login`, `/sitemap.xml`, and `/feed`.

## Troubleshooting

### The preflight reports `managed-untracked`

The install does not have file hashes for bundled themes/plugins, usually
because it predates `typedock-package.json` hashes or was installed from
a development archive. Treat these directories as locally modified:
back them up before replacing them.

### The preflight reports an ownership `collision`

A local user-owned theme/plugin uses a slug that a new TypeDock release
wants to claim as bundled. Stop the upgrade and rename or remove the
local extension after reviewing it.

### The admin looks unstyled after upgrade

Run:

```bash
php cli/assets-publish.php
php cli/cache-clear.php
```

Then clear any host-level cache or CDN cache.

### Everyone was signed out of the admin after upgrading

Expected once, on the release that renamed the cookies. The admin login
cookie is now `typedock_auth` (was `cms_session`) and the PHP session cookie
is `typedock_session` (was `PHPSESSID`), so both share a `typedock_` prefix
and a CDN can bypass the cache for signed-in visitors with one rule. Sign in
again; nothing else is affected. Override the names with `AUTH_COOKIE_NAME`
and `SESSION_NAME` in `config.php` if you need the old ones back.

### Migrations fail

Do not keep browsing the half-upgraded site. Restore the database backup,
restore the previous files, clear caches, and retry after reading the
migration error.
