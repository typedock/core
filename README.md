<div align="center">

# TypeDock

**A modern PHP CMS — a different path for sites that have outgrown WordPress's trade-offs.**

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-RC-blue.svg)]()

</div>

---

TypeDock is an open-source CMS that offers a different set of trade-offs from WordPress. Where WordPress chose maximum extensibility, TypeDock chooses a smaller, type-safe, server-rendered core with stricter boundaries between themes and plugins. It is intended as an **alternative**, not a replacement.

> **Status:** Release candidate. Auth (session + API key + 2FA + brute-force lockout), content, media, SEO, search, theme engine, admin MPA, Tiptap 3 block editor, drop-in plugin system, External Source headless mode, and an OWASP-aligned security baseline are all working. 210 PHPUnit tests pass on SQLite, MySQL 8, and PostgreSQL 14.

## Where TypeDock differs

| Area              | WordPress                                | TypeDock                                                                              |
| ----------------- | ---------------------------------------- | ------------------------------------------------------------------------------------- |
| Plugins           | Open marketplace, broad supply-chain     | Drop-in `plugins/<slug>/` with manifest, auto-prefixed routes/tables, iframe admin UI |
| Themes            | Can run PHP and query the DB             | Latte templates + CSS + JS only. XSS structurally eliminated by auto-escaping         |
| Custom fields     | `wp_postmeta` EAV                        | Page (free-form) + Collection (Notion-like) — no meta-table soup                      |
| Dynamic fragments | Shortcodes, widgets, blocks, tags        | One interface: `{component}` / `{slot}` + declarative `theme.json` `fetch`            |
| External content  | Per-source plugins                       | Built-in External Source: Contentful, GitHub Issues, Generic JSON                     |
| Deployment        | Unzip to `public_html`, edit wp-config   | **The same**: unzip, edit `config.php` (or let the installer write it), open browser  |

## Features

- **Content** — Pages, Posts, Categories, Tags, Revisions, scheduling, trash/restore, translation groups, author profiles
- **Block editor** — Tiptap 3 with slash menu, floating toolbar, Media Picker, OGP bookmark cards, oEmbed (YouTube/Vimeo/X/Spotify/SoundCloud), embeddable `{component}` blocks. Body stored as Tiptap JSON, server-rendered by `TiptapRenderer` — HTML never persisted.
- **Auth & RBAC** — Session cookies + API keys + TOTP 2FA + brute-force lockout (login *and* 2FA verify). 4 roles, 30 named permissions, defense-in-depth ownership checks.
- **Security baseline** — CSP, X-Frame-Options, X-Content-Type-Options, Referrer/Permissions-Policy, HSTS (HTTPS), HttpOnly + SameSite session cookies, `server_tokens off`, `expose_php = Off`. OWASP ZAP scan tooling included (`make security-scan`).
- **External Source** — Read-only headless mode pulling content from Contentful, GitHub Issues, or any JSON HTTP API. Credentials encrypted at rest via AES-256-GCM keyed off `APP_KEY`.
- **Plugins** — Drop-in `plugins/<slug>/` with manifest, optional iframe-isolated admin UI, zip upload installer, `provides` collision detection. Bundled: `form`, `redirect`, `social`, `image-optimizer`, `turnstile-captcha`, `advanced-blocks`, `backup`, `source-contentful`, `source-github`.
- **Themes** — Layout + partial + component overrides; `theme.json` settings + custom components + declarative `fetch`. Bundled: `default`, `kinari`, `kawara`, `northline`. See [docs/theme-development.md](docs/theme-development.md).
- **Multi-DB & multi-language** — MySQL 8, PostgreSQL 14, SQLite 3.35+ on the same migrations. `locale` column on content with translation groups; default `en`. Optional locale routing (`/ja/about`).
- **Shared-hosting friendly** — Pre-built zip with split `public_html/` + `typedock/` layout, browser installer, single `config.php`. No SSH or Composer required on the host.

## Tech stack

PHP 8.2+ · [FlightPHP](https://flightphp.com/) 3.17+ · [Latte](https://latte.nette.org/) 3.1+ · [Ramsey UUID](https://uuid.ramsey.dev/) v7 · [Tiptap](https://tiptap.dev/) 3 (esbuild-bundled) · [PHPMailer](https://github.com/PHPMailer/PHPMailer) · MySQL 8+ / PostgreSQL 14+ / SQLite 3.35+

## Quick start

### Docker (local dev)

```bash
make dev               # build + start app (php-fpm) + nginx on http://localhost:8080
make dev-mysql         # or with MySQL
make dev-postgres      # or with Postgres
make install           # run the CLI installer (create admin, activate theme)
make test              # run the PHPUnit suite
make security-scan     # OWASP ZAP baseline scan
```

The default stack uses SQLite on disk; MySQL/Postgres are gated behind compose profiles.

### Shared hosting (no command line)

1. Download `typedock-shared.zip` and upload its contents to `public_html`.
2. Make `storage/` writable (`chmod 755` on most hosts).
3. Open `https://your-domain/` — the installer wizard starts automatically. Fill in DB details + admin account.
4. The wizard writes `config.php` and offers to delete `install.php`. Done.

> The shared-hosting zip ships `public/admin/assets/css/admin.css` and `public/admin/dist/editor.bundle.{js,css}` already compiled, so neither Tailwind nor Node.js is required on the host. Only core developers editing the admin UI or Tiptap source need `make assets`.

### Configuration

TypeDock reads a single `config.php` at the project root (analogous to `wp-config.php`). Real environment variables override values in `config.php`, so the same file works on shared hosting *and* in container/PaaS deploys.

## Project layout

```
typedock/
├── public/        Entry point (index.php, install.php, .htaccess)
├── src/           PSR-4: TypeDock\ (Core, Auth, Content, ExternalSource, Mail, Locale, Media, Seo, Component, Theme, Admin, Api, Middleware, Plugin)
├── config/        app, database, cache, auth, mail, filesystems, antispam, backup
├── migrations/    Database migrations (driver-agnostic schema builder)
├── themes/        Bundled: default, kinari, kawara, northline
├── plugins/       Drop-in plugins (manifest-based)
├── admin/         Admin UI Latte templates, CSS/JS, Tiptap editor source
├── cli/           install, migrate, cache-clear, export, import, seed, assets-publish
├── docker/        Compose-side configs (nginx, ZAP automation YAMLs)
└── storage/       Cache, logs, sessions, uploads, backups (gitignored)
```

## Plugins

Plugins are drop-in directories under `plugins/<slug>/` with a `plugin.json` manifest. They run as trusted PHP — TypeDock does not sandbox PHP execution — but the loader enforces three structural constraints:

- **Auto-prefixed routes** — public under `/plugin/{slug}/*`, admin under `/admin/plugins/{slug}/*`.
- **Auto-prefixed DB tables** — migrations create `plugin_{slug}_*` only; `PluginDatabase` blocks queries to other prefixes.
- **`provides` collision detection** — manifest-declared providers; conflicts surface in `PluginDiagnostics` instead of silently overwriting.

Plugin admin UIs render inside an iframe, isolating their CSS/JS from the core admin.

## CLI

```bash
php cli/install.php         # Interactive install
php cli/migrate.php         # Apply DB migrations
php cli/cache-clear.php     # Clear template + HTML cache
php cli/export.php          # Export all content to JSON
php cli/import.php          # Import a previously exported archive
php cli/assets-publish.php  # Copy theme/plugin assets into public/{themes,plugins}/
php cli/upgrade.php         # Upgrade preflight + coding-agent handoff context
php cli/seed.php            # Insert demo content
```

## Upgrading

TypeDock uses an agent-assisted upgrade model: Core tells you which files
are managed, which themes/plugins are user-owned, and what a coding agent
or human operator should preserve. See [docs/upgrade.md](docs/upgrade.md).

## Releasing

Release tags are created only after CI is green on `main`. See
[docs/release.md](docs/release.md) for the preflight checklist and tagging
commands.

## Contributing

1. Open an issue first for anything touching the data model, theme API, or component contract.
2. Keep architectural constraints intact:
   - Themes never run PHP or touch the DB
   - Multi-DB compatibility (no `ENUM` / `JSON` / `ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`)
   - Dynamic parts go through `{component}`

## Roadmap

- [x] Drop-in plugin architecture, Tiptap 3 editor, security baseline, External Source mode, Docker stack, multi-DB CI
- [ ] Nonce-based CSP for admin (drop `'unsafe-inline'`)
- [ ] Sub-Resource Integrity attributes on bundled scripts
- [ ] Pluggable search drivers (Meilisearch, Algolia)
- [ ] Collection (structured content) — design done, implementation post-1.0
- [ ] Public plugin / theme registry

## License

Apache License 2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE). Contributions are accepted under the same license; there is no separate contributor license agreement.

## Acknowledgements

Design informed by **[Cloudflare EmDash](https://github.com/cloudflare/emdash)**, **[Statamic](https://statamic.com/)** / **[Kirby](https://getkirby.com/)** / **[Craft](https://craftcms.com/)**, **[Notion](https://www.notion.so/)** (Page/Database split), and twenty years of WordPress's deployment model that we deliberately preserve.
