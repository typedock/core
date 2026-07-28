# TypeDock Upgrade Guide

Zip-managed TypeDock installations can update Core from
**Admin → System Update** beginning with `1.0.0-rc6`. Source checkouts and
containers continue to use their normal deployment workflow. The same page
retains a machine-readable context and agent prompt for hosts where PHP cannot
replace the installed files.

This keeps the update path portable across shared hosting, VPS, Docker,
Git checkouts, split `public_html` layouts, and sites with custom themes
or plugins.

## The Short Version

1. Open `/admin/system/update`.
2. Click **Check now**.
3. Click **Download and verify**.
4. Review ownership warnings for themes and plugins.
5. Confirm the maintenance notice and apply the staged release.

TypeDock verifies the release checksum and minisign signature against its
pinned primary/recovery keyring before staging. This lets a
recovery-signed release rotate a lost or compromised normal release key.
Applying creates database and file backups, enters maintenance mode, swaps
only manifest-owned paths, runs migrations, republishes assets, and verifies
the installed file hashes. A caught failure triggers automatic rollback.

If you use a coding agent, give it the prompt from `/admin/system/update`
or:

```bash
php cli/upgrade.php --agent-prompt
```

## What TypeDock Helps With

TypeDock Core provides:

- A package manifest: `typedock-package.json`
- Signed release download and safe zip staging
- Installation mode detection: `zip`, `source`, or `container`
- Theme/plugin ownership checks
- Database and replaced-file backups
- Maintenance mode, migration, asset publishing, and automatic rollback
- Agent handoff context:

```bash
php cli/upgrade.php --agent-context
php cli/upgrade.php --agent-prompt
```

In-place apply is deliberately limited to zip-managed installations.
Git/source and container installs show the preflight and agent context but do
not offer an apply button.

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
public/admin/assets/
public/index.php
public/install.php
config/
composer.json
composer.lock
LICENSE
README.md
```

Bundled themes and plugins are handled separately because users often
customize them by mistake.

Cloud Storage is distributed as a separate official plugin beginning with
TypeDock 1.0. Core release packages do not add or replace
`plugins/cloud-storage/`. If it is already installed, preserve that directory
during upgrades. New installations can download the
`typedock-cloud-storage-*.zip` asset from the matching GitHub release and
upload it from **Settings -> Modules**, or copy the extracted directory over
FTP when the host's upload limit is too small.

## Theme and Plugin Ownership

The updater preflight classifies theme and plugin directories:

| Status | Meaning | What to do |
|--------|---------|------------|
| `clean` | Bundled by TypeDock and unchanged | Safe to replace from the release |
| `modified` | Bundled by TypeDock but locally changed | Back it up, inspect the diff, then decide |
| `managed-untracked` | Bundled by TypeDock, but this install lacks package hashes | Treat as modified; back it up first |
| `removed-bundled` | Bundled by the old release but now distributed separately | Preserve it as an installed extension |
| `user-owned` | Not owned by TypeDock Core | Do not overwrite |
| `collision` | A user-owned slug conflicts with a new bundled slug | Stop and resolve manually |

Best practice: do not edit bundled themes like `default` or `kinari`
directly. Copy them to a new slug and customize the copy.

## Shared Hosting Upgrade

Use the Admin flow above when the site was installed from the shared-hosting
zip at `1.0.0-rc6` or newer. The manual fallback is:

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

If a caught error occurs after live replacement starts, TypeDock automatically
restores the recorded database and files. If the PHP request itself is killed
mid-swap, revisit `/admin/system/update` with the maintenance bypass link from
the interrupted session and click **Restore previous release**.

Manual rollback remains environment-specific:

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

The admin updater keeps maintenance mode active while migrating and attempts
rollback immediately. SQLite rollback is an exact file restore. MySQL,
PostgreSQL, and libSQL use a portable row snapshot; schema changes are
forward-only, so a migration that destructively removes old schema may still
require the hosting provider's database backup.
