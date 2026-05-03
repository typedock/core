# TypeDock Theme Development Guide

Introduction to building a TypeDock theme — directory layout, the
shipping checklist, and pointers to the per-topic references.

If you're porting a WordPress or Hugo theme, the quickest mental model
is:

- `theme.json` replaces `functions.php` for *declaring* what the theme
  exposes to the admin UI (settings, slots, menu locations). It does
  **not** contain behaviour.
- Template files are Latte (`.latte`), which is strictly server-rendered
  and auto-escapes everything by default.
- Themes **must not** talk to the database or run PHP logic. They render.
  Dynamic template output goes through `{component(...)}` or `{slot(...)}`.
  Page and post bodies use the Tiptap Component Block instead of Latte
  tags.

---

## Where to look next

| Topic | Read |
|-------|------|
| `theme.json` schema (metadata, settings, slots, menus, fetch) | [theme-json-reference.md](theme-json-reference.md) |
| Latte globals, `$page` / `$post` shape, image handling, partials | [theme-template-reference.md](theme-template-reference.md) |
| The bare-component-themed-chrome principle + class tables | [theme-components.md](theme-components.md) |
| CSS variables, asset switching, custom-CSS escape hatch | [theme-settings.md](theme-settings.md) |
| Latte 3 syntax gotchas (`{literal}`, `<script>`, `\|escapeUrl`, …) | [latte-quickref.md](latte-quickref.md) |

---

## 1. Directory layout

A theme lives in `themes/<slug>/` and has this shape:

```
themes/my-theme/
  theme.json                 # Declaration: metadata, settings schema, slots
  layouts/
    base.latte               # The root HTML document
    single.latte             # Blog post
    page.latte               # Static page
    archive.latte            # Category/tag/blog index
    search.latte             # Search results
    home.latte               # Homepage when home_mode = page
    author.latte             # Author archive
    403.latte / 404.latte / 500.latte
  partials/
    header.latte
    footer.latte
    breadcrumb.latte
    pagination.latte
    ...                      # Any supporting fragments
  components/                # Optional — per-theme overrides of shared components
    search-form.latte
    latest-posts.latte
    ...
  assets/
    css/style.css
    js/main.js
    screenshot.svg           # Preview image shown on /admin/themes
```

At runtime TypeDock publishes `themes/my-theme/assets/` into
`public/themes/my-theme/assets/` so the web server can serve the files
directly, without PHP. You reference those files from templates as
`{$theme->url}/assets/css/style.css` (see §2).

The `components/` directory is optional. When present, its files override
the built-in component templates *for your theme only*: drop a
`components/search-form.latte` and you restyle the search widget without
touching any other theme.

---

## 2. Assets

### 2.1 Publishing

`public/themes/<slug>/assets/` is populated by `AssetPublisher` on theme
activation and whenever `php cli/assets-publish.php` is run. The source
lives at `themes/<slug>/assets/`, and templates reference the published
URL via `{$theme->url}/assets/...`.

During development you can skip the CLI and just mirror your source dir:

```bash
php cli/assets-publish.php   # publishes all themes + plugins
```

### 2.2 The `screenshot.svg` convention

Drop `assets/screenshot.svg` (PNG / JPG / WEBP also supported) and it
will be shown as the theme's preview on `/admin/themes`. Recommended
size: 1200×900 @ 2x.

### 2.3 Third-party assets

Theme-supplied JS is loaded with `defer`. Keep it framework-free when
possible — TypeDock's admin already ships no JS framework on the
frontend, so adding one just for a menu toggle goes against the grain.

---

## 3. Demo content for development

You don't need to author posts by hand to start theming. After
installing TypeDock, run:

```bash
php cli/seed.php
```

…to drop in a baseline set of categories, tags, posts, pages, and
menus. The seed is idempotent — re-running skips rows that already
exist, and it never touches operator-authored content. You can also
combine it with the installer in one shot:

```bash
php cli/install.php --with-demo
```

The seed targets every layout your theme ships: home (archive mode),
single post, static page, category / tag archives, search, and the
author archive. If a layout still looks empty after seeding, the issue
is in the template, not the database.

For a disposable preview loop that does not touch your real
`config.php` or site database, run:

```bash
php cli/theme-preview.php my-theme --port 8080
```

This creates `.preview/my-theme/preview.sqlite`, runs migrations, seeds
preview content, activates the target theme in that sandbox, publishes
the theme assets, and starts PHP's built-in server. The command prints
URLs for home, single, page, archive, category, tag, search, author,
403, 404, and 500 layouts.

If Playwright is already installed in the project, add `--screenshot`
to save full-page PNGs under `.preview/my-theme/screenshots/`.

---

## 4. A checklist for shipping a new theme

Before you publish a theme on a marketplace or submit it to the
TypeDock theme repository:

- [ ] `theme.json` has `name`, `version`, `author`, `description`
- [ ] At least `base`, `single`, `page`, `archive`, `search`, `404`
      layouts render without error against a seeded database
      (run `php cli/seed.php` and walk every URL)
- [ ] `.sr-only` and `.skip-link` are defined in your CSS
- [ ] Every slot your theme declares has a sensible `defaults` array
- [ ] Every `menus.<location>` declared in `theme.json` is consumed
      somewhere — by a `$site->menu('<location>')` call or a
      `{component('menu', ['location' => '<location>'])}` widget
- [ ] Location keys describe placement/role (`header`, `footer`,
      `mobile`) rather than abstract priority (`primary`, `nav1`)
- [ ] `partials/breadcrumb.latte` and `partials/pagination.latte`
      exist and are included from the relevant layouts
- [ ] Every settings field has a `default`
- [ ] The theme does not read from the database directly — everything
      dynamic in templates flows through `{component}`, `{slot}`, or a
      `theme.json` `fetch` declaration
- [ ] Author-facing instructions for page/post content use the Tiptap
      Component Block, not Latte `{component}` snippets
- [ ] Theme CSS defines semantic tokens used by component chrome, such
      as `--color-accent`, `--color-on-accent`, `--color-border`, and
      `--color-surface`
- [ ] Common plugin component classes your users are likely to enable
      are styled or intentionally left bare, especially `.td-form` and
      `.td-social-*`
- [ ] Components intended for External Source list views declare
      `source_list.compatible` and their mappable inputs in `theme.json`
- [ ] Switching between `font-style--sans` / `--serif` etc. does not
      leave stray `var(--font-serif)` references unset
- [ ] `screenshot.svg` (or .png/.jpg/.webp) is present under `assets/`
- [ ] `advanced.custom_css` (or equivalent) is exposed so users can
      override per-locale needs without forking
- [ ] All `<img>` elements pair with `$post->thumbnailAlt` (or a
      hard-coded alt attribute, including empty string for purely
      decorative images)

### 4.1 Core component CSS class table

Core components render bare semantic markup with stable classes. Themes
own the visual chrome around those classes. The table below is the
public contract for bundled components most themes target.

| Component | Root class | Stable internal classes | Params that change structure |
|-----------|------------|-------------------------|------------------------------|
| `search_form` | `.search-form` | `.sr-only`, `.search-submit` | `placeholder` changes the input placeholder only. |
| `latest_posts` | `.widget.widget-latest-posts` | `.widget-title`, `.post-list`, `.post-list-item`, `.post-list-item-thumb`, `.post-list-item-body` | `title` renders `<h3 class="widget-title">` when non-empty; blank title removes the heading. `count` changes item count only. Thumbnail markup appears only when the post has `$post->thumbnail`. |
| `category_list` | `.widget.widget-category-list` | `.widget-title`, `.category-list`, `.count` | `title` renders `<h3 class="widget-title">` when non-empty; blank title removes the heading. `.count` appears only for categories with posts. |
| `tag_cloud` | `.widget.widget-tag-cloud` | `.widget-title`, `.tag-cloud`, `.tag-cloud-item` | `title` renders `<h3 class="widget-title">` when non-empty; blank title removes the heading. `limit` changes item count only. |
| `related_posts` | `.related-posts` | `.related-posts-title`, `.widget-title`, `.related-posts-grid`, `.related-post-card`, `.related-post-thumb` | `title` renders `<h3 class="related-posts-title widget-title">` when non-empty; blank title removes the heading. `count` changes item count only. Thumbnail markup appears only when the post has `$post->thumbnail`. Requires post context. |
| `author_profile` | `.author-profile` | `.author-avatar`, `.author-info`, `.author-name`, `.author-bio`, `.author-links` | No params. Avatar, bio, website, and social links render only when the author profile has those values. Requires post or page context. |
| `menu` | `.menu-list` | `.menu-item`, `.has-children`, `.sub-menu` | `location` selects the theme-declared menu location. `.has-children` and `.sub-menu` appear only for nested items. Menu item custom classes from admin are appended to `.menu-item`. |
| `link_list` | `.link-list`, plus `.link-list--horizontal` or `.link-list--vertical` | `.link-list__item` | `links` controls rendered anchors. `layout` changes the root modifier class. Empty links render nothing. |

The complete component design contract, including plugin component
classes and the bare-component-themed-chrome principle, lives in
[theme-components.md](theme-components.md).

---

## 5. Further reading

- `themes/default/` — the minimal starting point
- `themes/kinari/` — polished single-author / journal theme
- `themes/northline/` — magazine theme, demonstrates `theme.json` `fetch`
- `themes/kawara/` — magazine theme, demonstrates route-data + slot
  styling without `fetch`
