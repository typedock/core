<div align="center">

# TypeDock

**A modern PHP CMS — a different path for sites that have outgrown WordPress's trade-offs.**

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-alpha-orange.svg)]()

</div>

---

TypeDock is an open-source CMS that offers a different set of trade-offs from WordPress — the CMS that built the open web and still powers a large share of it. Where WordPress chose maximum extensibility, TypeDock chooses a smaller, type-safe, server-rendered core with stricter boundaries between themes, modules, and plugins. It is intended as an **alternative**, not a replacement: pick whichever fits the project in front of you.

> **Status:** Alpha. The core framework, auth, content, media, theme engine, and admin UI are working. The block editor UI (Tiptap integration) and module business logic are next.

## Where TypeDock differs

WordPress solves a huge range of problems brilliantly. TypeDock makes different choices for teams who want tighter boundaries up front and are willing to give up some of WordPress's extensibility in exchange.

| Area                         | WordPress convention                                                    | TypeDock's choice                                                                                                              |
| ---------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Plugin distribution          | Open marketplace — maximum ecosystem, broad supply-chain surface        | No third-party plugin marketplace. Core features ship as **official modules** (toggle on/off). Plugins run in a sandboxed API. |
| Theme capabilities           | Themes can contain PHP and run DB queries                               | Themes are **Latte templates + CSS + JS only**. No raw PHP, no DB access. XSS is structurally eliminated by auto-escaping.     |
| Custom-field storage         | `wp_postmeta` EAV — very flexible, query-heavy at scale                 | Separate **Page** (free-form content) and **Collection** (structured, Notion-like databases) models. No meta-table soup.      |
| Admin / frontend coupling    | Admin and frontend share the same PHP process and theme                 | Admin is an isolated MPA. Content can be served to any existing site — even a membership site with its own auth.               |
| Dynamic template fragments   | Shortcodes, widgets, block patterns, and template tags are all separate | **One** dynamic-part interface: `{component}` / `{slot}`. Everything dynamic goes through it.                                  |
| Deployment                   | Unzip to `public_html`, edit `wp-config.php`, run browser installer     | **The same**: unzip to `public_html`, edit `config.php` (or let the installer write it), visit the site in a browser.           |

## Features

- **Content** — Pages, Posts, Categories, Tags, Revisions, scheduled publishing, trash/restore, translation groups
- **Block editor** — Markdown-first body with extensions (`==mark==`, `[card:/path]` link cards) plus per-type blocks (image, gallery, embed, bookmark, HTML, separator, component)
- **Media** — Uploads with automatic thumbnail generation (sm/md/lg), focal points, alt text, folder organization
- **SEO** — Per-page meta, Open Graph, Twitter cards, JSON-LD schema, `sitemap.xml`, RSS feed, `robots.txt`
- **Search** — Built-in LIKE-based search; pluggable `SearchEngine` contract for Meilisearch/Algolia/Elasticsearch
- **Auth** — Session cookies (admin) + API keys (external integrations) + TOTP 2FA + password reset + brute-force lockout
- **RBAC** — 4 roles (admin/editor/author/contributor), 30 named permissions
- **Themes** — Layout + partial + component overrides. Default theme included. Activate via admin.
- **Components & Slots** — `{component "latest_posts" count=5}` in any template; admin-configurable slot placements per theme
- **Redirects** — Exact-path redirects + pluggable regex resolvers via the Redirect module
- **Backup** — JSON export/import of all content (DB is the source of truth, files are export artifacts)
- **Multi-database** — MySQL, PostgreSQL, and SQLite run on the same migrations (no vendor-specific SQL)
- **Multi-language** — Locale column on content with translation groups; default `en`
- **Shared-hosting friendly** — Pre-built zip, browser-based installer, single `config.php` in the spirit of `wp-config.php`; no SSH or Composer required on the target host

## Architecture at a glance

```
┌─────────────────────────────────────────────────────────────┐
│                        Browser                              │
└───────────────┬─────────────────────┬───────────────────────┘
                │ HTML (SSR)          │ HTML (SSR)
┌───────────────▼─────────┐ ┌─────────▼───────────────────────┐
│   Frontend (public)     │ │   Admin (/admin/*)              │
│   FlightPHP + Latte     │ │   FlightPHP + Latte + CSRF      │
│   Theme templates       │ │   Session cookie auth           │
└───────────────┬─────────┘ └─────────┬───────────────────────┘
                │                     │
                │  ┌──────────────────▼──────────────────┐
                │  │     External API (/api/v1/*)        │
                │  │     Bearer API key auth             │
                │  └──────────────────┬──────────────────┘
                │                     │
┌───────────────▼─────────────────────▼───────────────────────┐
│                      Core services                          │
│   PageService · MediaService · SeoService · Auth · ...      │
│   ComponentRenderer · BlockRenderer · ThemeLoader           │
└───────────────┬─────────────────────────────────────────────┘
                │
┌───────────────▼─────────────────────────────────────────────┐
│     Official modules (toggle on/off)                        │
│   Collection · Redirect · Mail · Antispam · Backup · i18n   │
└───────────────┬─────────────────────────────────────────────┘
                │
┌───────────────▼─────────────────────────────────────────────┐
│     Plugins (sandboxed via PluginContext)                   │
│   Form · Social · AdvancedBlocks · ImageOptimizer · ...     │
└───────────────┬─────────────────────────────────────────────┘
                │
┌───────────────▼─────────────────────────────────────────────┐
│     Database (MySQL / PostgreSQL / SQLite)                  │
│     Managed by the built-in migration runner                │
└─────────────────────────────────────────────────────────────┘
```

## Tech stack

- **PHP** 8.2+
- **[FlightPHP](https://flightphp.com/)** 3.17+ — micro framework
- **[Latte](https://latte.nette.org/)** 3.1+ — template engine with context-aware escaping
- **[Ramsey UUID](https://uuid.ramsey.dev/)** — UUID v7 primary keys
- **[league/commonmark](https://commonmark.thephpleague.com/)** — Markdown
- **[PHPMailer](https://github.com/PHPMailer/PHPMailer)** — email
- **MySQL 8+** / **PostgreSQL 14+** / **SQLite 3.35+**

## Quick start

### Docker (recommended for local dev)

```bash
# One-liner: build + start app (php-fpm) + nginx on http://localhost:8080
make dev

# With MySQL or Postgres instead of SQLite:
make dev-mysql
make dev-postgres

# Helpful follow-ups:
make install     # run the CLI installer inside the container
make migrate     # run Phinx migrations
make shell       # shell into the app container
make down        # stop everything
```

The default stack uses SQLite on disk (no database container needed). MySQL and
Postgres are gated behind compose profiles so they only start when you ask for
them.

### Shared hosting (no command line)

1. Download the latest `typedock-shared.zip` release and upload its contents to your `public_html` directory.
2. Make sure `storage/` is writable (most hosts: `chmod 755` is enough).
3. Open `https://your-domain/` in a browser — the installer wizard starts automatically. Fill in your database details and an administrator account.
4. The wizard writes `config.php` and offers to delete `install.php` for you. Done.

### Local development

```bash
# 1. Clone & install dependencies
git clone https://github.com/your-org/typedock.git
cd typedock
composer install

# 2. Prepare storage directories
mkdir -p storage/{cache,logs,sessions,tmp,uploads,backups}

# 3. Build admin CSS (core-developer only — distributions ship prebuilt)
make assets            # or: npm install && npm run build:css

# 4. Start a dev server
php -S localhost:8000 -t public

# 5. Open http://localhost:8000/ — the browser installer runs on first access.
#    (Or edit config.php directly; see config.php.example for all keys.)
```

> **Note** — The zero-build promise applies to end users: the shared-hosting
> zip ships with `public/admin/assets/css/admin.css` already compiled, so
> Tailwind is never required on the host. Only core developers working on
> the admin UI need Node.js and `make assets` (or `make assets-watch`).

Then visit:

| URL                                   | What you get                 |
| ------------------------------------- | ---------------------------- |
| `http://localhost:8000/`              | The public site (default theme) |
| `http://localhost:8000/admin/login`   | Admin sign-in                |
| `http://localhost:8000/sitemap.xml`   | XML sitemap                  |
| `http://localhost:8000/feed`          | RSS 2.0 feed                 |
| `http://localhost:8000/robots.txt`    | robots.txt                   |

### Configuration

TypeDock reads configuration from a single `config.php` file at the project root (analogous to WordPress's `wp-config.php`). Real environment variables override values in `config.php`, so the same file works on shared hosting *and* in container/PaaS deploys.

### Production

Point your web server document root at `public/` where possible. On shared hosts where the document root is fixed, the included root-level `.htaccess` routes requests into `public/index.php` and blocks direct access to `config/`, `src/`, `storage/`, `vendor/`, and `config.php`. An equivalent Nginx rewrite:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Project layout

```
typedock/
├── public/          Entry point (index.php + .htaccess)
├── src/             PSR-4: TypeDock\ (Core, Auth, Content, Media, Seo, Component, Theme, Admin, Api, Module, Plugin)
├── config/          app, database, cache, auth, mail, filesystems, modules
├── migrations/      Database migrations (driver-agnostic schema builder)
├── themes/default/  Default theme (layouts, partials, components, assets, theme.json)
├── admin/           Admin UI Latte templates + CSS/JS
├── cli/             install, migrate, cache-clear, export scripts
├── plugins/         Drop-in third-party plugins
└── storage/         Cache, logs, sessions, uploads, backups (gitignored)
```

## Themes

Themes are **templates only** — Latte, CSS, and JS. No PHP logic, no database access. Any dynamic value must come through a registered component:

```latte
{* themes/my-theme/layouts/single.latte *}
{layout 'base.latte'}

{block content}
    <article>
        <h1>{$page->title}</h1>
        {$page->renderedBody|noescape}
    </article>

    {* Dynamic parts: always via {component} *}
    {=component('related_posts', ['count' => 6])|noescape}
{/block}
```

A minimal `theme.json` declares slot defaults:

```json
{
  "name": "My Theme",
  "version": "1.0.0",
  "slots": {
    "sidebar": {
      "label": "Sidebar",
      "defaults": [
        {"component": "search_form"},
        {"component": "latest_posts", "params": {"count": 5}},
        {"component": "category_list"}
      ]
    }
  }
}
```

## Components

Register new components from a plugin or module:

```php
$context->registerComponent(
    new ComponentDefinition(
        type: 'weather_widget',
        name: 'Weather Widget',
        description: 'Shows the current weather for a city',
        params: ['city' => 'Tokyo'],
        placeable: true,
        template: 'components/weather.latte',
        dataProvider: new WeatherProvider(),
    )
);
```

Use it anywhere in a theme:

```latte
{=component('weather_widget', ['city' => 'London'])|noescape}
```

## Modules vs plugins

|                          | **Modules** (official)                                   | **Plugins** (third-party)                      |
| ------------------------ | -------------------------------------------------------- | ---------------------------------------------- |
| Who writes them          | TypeDock core team                                       | Anyone                                         |
| Access to                | Direct core services, full DB, any file                  | Sandboxed `PluginContext` API only             |
| DB access                | Any table                                                | Auto-prefixed `plugin_{slug}_*` tables only    |
| Routes                   | Any path                                                 | Auto-prefixed `/plugin/{slug}/*`               |
| Distribution             | Bundled in core                                          | Drop-in to `/plugins/` directory               |
| Official modules shipped | Collection · Redirect · Mail · Antispam · Backup · i18n  | —                                              |

This split means plugins cannot break the site or steal data from other plugins — a structural improvement over WordPress's all-or-nothing `require`.

## CLI

```bash
php cli/install.php         # Interactive install (create admin, activate theme)
php cli/migrate.php          # Apply DB migrations
php cli/cache-clear.php     # Clear template + HTML cache
php cli/export.php          # Export all content to JSON
```

## Contributing

Contributions are very welcome. Before you start:

1. Read the [contribution guide](CONTRIBUTING.md) *(if present)*.
2. Open an issue describing the change first — especially for anything touching the data model, theme API, or component contract.
3. Keep the architecture constraints intact:
   - Themes never run PHP or touch the DB.
   - Multi-DB compatibility (no `ENUM` / `JSON` / `ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`).
   - All dynamic parts go through `{component}`.

## Roadmap

- [ ] Block editor UI (Tiptap-based)
- [ ] Module implementations (Collection / Redirect / Mail / Antispam / Backup / i18n)
- [ ] Full test suite (PHPUnit + integration tests across MySQL/PostgreSQL/SQLite)
- [ ] Pluggable search drivers (Meilisearch, Algolia)
- [ ] First-class Docker image
- [ ] Public plugin/theme registry

## License

MIT © TypeDock contributors. See [LICENSE](LICENSE).

## Acknowledgements

TypeDock's design was informed by:

- **[Cloudflare EmDash](https://github.com/cloudflare/emdash)** — the spiritual successor to WordPress that set the direction
- **[Statamic](https://statamic.com/)**, **[Kirby](https://getkirby.com/)**, **[Craft CMS](https://craftcms.com/)** — flat-file & structured-content CMSes
- **[Notion](https://www.notion.so/)** — the Page / Database distinction
- **WordPress** — twenty years of democratizing publishing on the open web, and the deployment model (upload, edit config, install in a browser) we deliberately preserve here
