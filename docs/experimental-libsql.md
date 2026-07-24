# Experimental Remote libSQL Driver

TypeDock can use a remote libSQL-compatible database through the experimental
`DB_DRIVER=libsql` connection path. It targets immutable or ephemeral
application containers where the database must persist outside the container.

The driver currently supports:

- Turso Cloud SQL over HTTP;
- Bunny Database SQL API.

It sends the Hrana v2 protocol directly over HTTPS. No local database, PHP FFI
extension, native library, or additional Composer package is required. The
driver works with TypeDock's normal PHP 8.2+ requirement.

The HTTP client reuses one cURL handle for the lifetime of each PHP request so
sequential statements can share libcurl's DNS and HTTPS connection caches.

This path remains experimental and is not included in TypeDock's stable MySQL,
PostgreSQL, and SQLite support matrix.

## Configuration

Use the provider-neutral variables for new deployments:

```env
DB_DRIVER=libsql
LIBSQL_DATABASE_URL=libsql://your-database.turso.io
LIBSQL_AUTH_TOKEN=replace-with-a-secret
```

Turso URLs may use either `libsql://` or `https://`. Bunny provides the full
pipeline endpoint:

```env
DB_DRIVER=libsql
LIBSQL_DATABASE_URL=https://your-database-id.lite.bunnydb.net/v2/pipeline
LIBSQL_AUTH_TOKEN=replace-with-a-secret
```

Provider-specific variables are also accepted when the generic variables are
empty:

```env
TURSO_DATABASE_URL=
TURSO_AUTH_TOKEN=

BUNNY_DATABASE_URL=
BUNNY_DATABASE_AUTH_TOKEN=
```

Optional HTTP settings:

```env
LIBSQL_HTTP_TIMEOUT=15
LIBSQL_CONNECT_TIMEOUT=5
LIBSQL_ALLOW_INSECURE=false
```

`LIBSQL_ALLOW_INSECURE` should remain false. Plain HTTP is accepted without
that flag only for loopback development URLs.

No local database path is used. TypeDock maps its schema builder to the SQLite
grammar and skips local-only SQLite pragmas such as WAL, `busy_timeout`, page
size, and memory mapping.

The browser installer exposes **Remote libSQL / Turso / Bunny
(experimental)** and runs `SELECT 1` against the supplied endpoint before
continuing.

## Docker

The published Docker image includes `ext-curl`, which the driver uses for
outbound HTTPS. The image remains Alpine-based and does not enable FFI.

There is no separate Composer installation step for this driver:

```bash
docker compose up --build
```

The regular image build installs TypeDock's locked Composer dependencies and
the Hrana adapter is part of Core.

## PDO Compatibility Layer

PHP does not provide a native PDO libSQL driver. TypeDock therefore implements
the subset of `PDO` and `PDOStatement` used by Core:

- prepared statements with positional and named parameters;
- `query()` and `exec()`;
- `fetch()`, `fetchAll()`, `fetchColumn()`, and statement iteration;
- write-only transactions through conditional Hrana batches;
- `lastInsertId()` and SQLite-compatible quoting;
- text, integer, float, null, boolean, and BLOB parameters.

The adapter reports `sqlite` for `PDO::ATTR_DRIVER_NAME` so existing
SQLite-compatible migration and SQL inspection branches continue to work.
This is a compatibility adapter, not a general-purpose replacement for every
PDO feature.

## Transactions and Provider Limits

Remote libSQL transactions are sent as one non-interactive Hrana conditional
batch. The batch runs `BEGIN`, executes each write only when every preceding
step succeeded, commits only after every write succeeded, and otherwise runs
`ROLLBACK`. This keeps the transaction to one HTTP round trip and does not
require an interactive baton. The conditional batch path has been verified
against Bunny Database as well as the protocol shape used by Turso.

TypeDock buffers statements between `beginTransaction()` and `commit()` for
this purpose. Only write statements are allowed in that interval. A `SELECT`,
`PRAGMA`, `EXPLAIN`, `VALUES`, `WITH`, `RETURNING`, or explicit transaction
control statement raises an exception; reads must be performed before the
transaction starts. Core theme activation and External Source writes follow
this rule.

The import CLI performs remote libSQL imports row by row without a surrounding
transaction because each row first checks whether it exists and import already
allows per-row errors. An interrupted remote import can therefore be partial.
Stable PDO drivers retain the existing transaction around the import.

Bunny's SQL API is currently beta, and this TypeDock driver remains
experimental. Validate provider behavior before treating it as production-ready.

## Deployment Notes

- Run `php cli/migrate.php` once from a deployment job. Do not run migrations
  concurrently from every application instance.
- Keep `APP_KEY` and the database token stable in a secret manager.
- Use the Cloud Storage plugin or another external storage provider for media;
  the container filesystem remains ephemeral.
- Treat themes and plugins as image contents rather than installing or editing
  them inside a running immutable container.
- Put the application and database in nearby regions. Remote-only mode adds a
  network round trip to each database statement.
- Validate consistency behavior for the chosen provider and topology,
  especially read-after-write behavior when reads may be served by replicas.
- Local template, HTTP, and HTML caches are disposable and may be rebuilt after
  a cold start.
