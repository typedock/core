# Backup

Creates, downloads, and restores tar.gz archives of the site so it can be migrated, rolled back, or moved between hosts.

## What's in an archive

- `db.sql.gz` — a driver-agnostic SQL dump of every table (works for MySQL / PostgreSQL / SQLite).
- `uploads/` — the entire `storage/uploads/` tree.

The dump is generated in pure PHP via PDO so the host does not need `mysqldump`, `pg_dump`, or any external tooling.

## Admin UI

Enable the plugin from `/admin/settings/modules`, then visit `/admin/plugins/backup` to:

- **Create backup now** — runs in the current request. For large sites, raise `max_execution_time` or run the CLI equivalent.
- **Download** — streams the `.tar.gz` to your browser.
- **Restore** — replaces the database and `uploads/` with the contents of the archive. Confirmation is required because this is destructive.
- **Delete** — removes the file from disk and the entry from the history table.

## Schema

The `backups` history table is part of Core's schema (it lives in the initial migration set), so the table is present even when this plugin is disabled. Disabling the plugin only removes the UI and runtime — it does not drop history.

## Configuration

Backup destinations come from `config/backup.php`:

| Key | Env | Default |
|---|---|---|
| `backup.dir` | `BACKUP_DIR` | `storage/backups` |
| `backup.uploads_dir` | — | `storage/uploads` |

Make sure the backup directory is **outside** `public/` so archives are not web-reachable.

## Permissions

All routes are mounted under `/admin/plugins/backup` and protected by the standard admin authentication + CSRF middleware. Only signed-in admins can create, download, restore, or delete backups.
