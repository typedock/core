# TypeDock Theme Development Guide

This guide walks you through building a TypeDock theme — from the directory
layout and `theme.json` schema through to the design principles that keep
themes portable across the components and plugins shipped by TypeDock and
the wider ecosystem.

If you're porting a WordPress or Hugo theme, the quickest mental model is:

- `theme.json` replaces `functions.php` for *declaring* what the theme
  exposes to the admin UI (settings, slots, menu locations). It does
  **not** contain behaviour.
- Template files are Latte (`.latte`), which is strictly server-rendered
  and auto-escapes everything by default.
- Themes **must not** talk to the database or run PHP logic. They render.
  Everything dynamic goes through `{component(...)}` or `{slot(...)}`.

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
    403.latte / 404.latte / 500.latte
  partials/
    header.latte
    footer.latte
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
`{$theme->url}/assets/css/style.css` (see §5).

The `components/` directory is optional. When present, its files override
the built-in component templates *for your theme only*: drop a
`components/search-form.latte` and you restyle the search widget without
touching any other theme.

---

## 2. `theme.json`

`theme.json` is the theme's public contract. TypeDock reads it to:

- show the theme on `/admin/themes`,
- generate the `/admin/theme-settings` form,
- seed default slot placements on activation.

### 2.1 Metadata

```json
{
  "name": "My Theme",
  "version": "1.0.0",
  "description": "What the theme is for, who it's aimed at.",
  "author": "Your name"
}
```

### 2.2 `settings` — user-tunable options

The `settings` block declares field groups that appear as tabs in
`/admin/theme-settings`. TypeDock handles persistence, form rendering, and
coercion; the theme only decides *what* is adjustable.

```json
{
  "settings": {
    "colors": {
      "label": "Colors",
      "fields": {
        "accent":     { "type": "color", "label": "Accent", "default": "#8c2a3a" },
        "header_bg":  { "type": "color", "label": "Header background", "default": "#1f1d1b" }
      }
    },
    "typography": {
      "label": "Typography",
      "fields": {
        "font_family": {
          "type": "select",
          "label": "Font style",
          "options": { "sans": "Sans-serif", "serif": "Serif", "mono": "Monospace" },
          "default": "sans"
        }
      }
    }
  }
}
```

**Supported field types:**

| type       | Admin UI                                | Stored as                 |
|------------|------------------------------------------|---------------------------|
| `color`    | Native color picker + hex text input     | string (`"#rrggbb"`)     |
| `text`     | Single-line text                         | string                    |
| `url`      | URL input                                | string                    |
| `number`   | Number input                             | integer                   |
| `boolean`  | Checkbox                                 | `true` / `false`          |
| `select`   | Dropdown (requires `options`)            | selected key              |
| `textarea` | Multi-line text (raw, not CSS-var-safe)  | string                    |
| `image`    | URL field (media picker planned)         | string URL or media id    |

Every field **should** declare a `default`. If omitted, the field is
treated as optional.

### 2.3 `slots` — where content plugs in

Slots are named regions your templates render components into. Each slot
has:

- a `label` shown in `/admin/slots`,
- a `context` list (`["post"]`, `["page", "archive"]`, or `["all"]`) so
  incompatible components are filtered out of the picker,
- an optional `defaults` array that's copied into `slot_placements` on
  theme activation.

```json
{
  "slots": {
    "header_right": {
      "label": "Header — right",
      "context": ["all"],
      "defaults": [{ "component": "search_form" }]
    },
    "sidebar": {
      "label": "Sidebar",
      "context": ["post", "page", "archive"],
      "defaults": [
        { "component": "search_form" },
        { "component": "latest_posts", "params": { "count": 5 } },
        { "component": "category_list" }
      ]
    },
    "after_content": {
      "label": "After content",
      "context": ["post"],
      "defaults": [
        { "component": "related_posts", "params": { "count": 6 } }
      ]
    }
  }
}
```

A slot with no defaults renders nothing until the site operator places
components into it via `/admin/slots`.

### 2.4 `menus` — navigation locations

Declare every navigation region your theme renders. Each entry becomes
a card on `/admin/menus`, and TypeDock auto-provisions the backing menu
row the first time the operator opens it — the admin never has to
create a "menu" entity by hand or guess a location key.

```json
{
  "menus": {
    "header": {
      "label": "Header Navigation",
      "description": "Main navigation shown in the header on every page."
    },
    "footer": {
      "label": "Footer Links",
      "description": "Legal / contact links rendered in the footer."
    },
    "mobile": {
      "label": "Mobile Drawer",
      "description": "Navigation surfaced when the mobile toggle opens."
    }
  }
}
```

Each location takes:

| Key           | Required | Purpose                                           |
|---------------|----------|---------------------------------------------------|
| `label`       | yes      | Human-readable title shown on `/admin/menus`.     |
| `description` | no       | One-line hint surfaced next to the label.         |

#### Naming

Name locations by **placement or role** (`header`, `footer`, `mobile`,
`utility`, `legal`). Avoid abstract names like `primary` or `nav1` —
the key is what the operator sees when editing, so make it describe
where the nav appears.

#### Rendering: `$site->menu()` is the primary pattern

Themes render navigation by consuming the data array directly. Call
`$site->menu('<location>')` and write the HTML yourself:

```latte
{* partials/header.latte *}
<nav class="site-nav" aria-label="Primary">
    <ul class="menu-list">
        {foreach $site->menu('header') as $item}
            <li class="menu-item {!empty($item->children) ? 'has-children' : ''}">
                <a href="{$item->url}">{$item->label}</a>
                {if $item->children}
                    <ul class="sub-menu">
                        {foreach $item->children as $child}
                            <li class="menu-item"><a href="{$child->url}">{$child->label}</a></li>
                        {/foreach}
                    </ul>
                {/if}
            </li>
        {/foreach}
    </ul>
</nav>
```

Shape of a `MenuItem`:

| Field          | Type        | Notes |
|----------------|-------------|-------|
| `label`        | string      | Human text shown in the link |
| `url`          | string      | Resolved at render time for Page/Post/Category targets, so slug changes don't break nav |
| `target_type`  | string      | `custom` / `page` / `post` / `category` |
| `css_class`    | ?string     | Optional per-item class (admin-set) |
| `children`     | MenuItem[]  | One level of nesting is supported |

Menu items nest one level deep (`parent_id`). The admin UI enforces
this limit so your CSS only has to style two levels.

#### The `menu` component is a drop-in fallback

`{component('menu', ['location' => 'header'])}` still works and is
registered as a slot-placeable widget. Use it when you want to offer a
navigation as **sidebar furniture** or a similar widget slot. For the
primary header / footer / mobile navs that every theme has, prefer
`$site->menu()` — it gives you full control over markup without a
per-component template override.

#### Locale

Locale is stored internally but not surfaced in the admin UI — assume a
single-language site for now. When TypeDock grows first-class
multilingual support, the `menus` contract will not change; the admin
gains a language switcher on top.

---

## 3. Templates

### 3.1 The Latte globals available to every template

| Variable        | Type                            | Purpose |
|-----------------|---------------------------------|---------|
| `$site`         | site-info object                | `$site->name`, `$site->url`, `$site->option('site.description')` |
| `$site->menu()` | `fn(string): MenuItem[]`        | `$site->menu('header')` returns the tree of menu items for the given location (§2.4). URLs are resolved for Page/Post/Category targets. |
| `$theme`        | ThemeContext                    | `$theme->url` (public assets root), `$theme->name`, `$theme->setting('group.field', default)` |
| `$themeStyle`   | ThemeStyleRenderer              | `$themeStyle->renderCssVariables()` — emit `--td-*` declarations |
| `$cms`          | feature-detection helper        | `$cms->hasModule('social')` |
| `$breadcrumbs`  | `BreadcrumbItem[]` (on content) | Auto-built trail: `Home › Blog › Category › Post`. Empty on 404/500/home. Render via `{include 'partials/breadcrumb.latte'}`. |
| `$pagination`   | PaginationData (on archive/search) | `current`, `totalPages`, `perPage`, `totalItems`, plus `hasPrev()`, `hasNext()`, `url(int $page)`, `range(int $window)`. |
| `$seo`          | SeoService result (on single)   | `$seo->title`, `$seo->canonical`, `$seo->jsonLd` — see `layouts/single.latte` |
| `$page`         | page object (on single)         | `$page->title`, `$page->renderedBody`, `$page->author->name` |
| `$body_class`   | string                          | Route-provided body class |

### 3.2 `base.latte` — the root document

Every concrete layout extends `base.latte` via `{layout 'base.latte'}`. A
minimal but production-ready `base.latte`:

```latte
<!DOCTYPE html>
<html lang="{$site->option('site.locale') ?? 'en'}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {* SEO block when present, or plain title fallback *}
    {if isset($seo)}
        <title>{$seo->title} - {$site->name}</title>
        {if $seo->description}<meta name="description" content="{$seo->description}">{/if}
        {$seo->jsonLd|noescape}
    {else}
        <title>{block title}{$site->name}{/block}</title>
    {/if}

    {* Theme-settings CSS variables — MUST come before your stylesheet so
       var(--td-*) references resolve to the saved values. *}
    {var $cssVars = $themeStyle->renderCssVariables()}
    {if $cssVars}<style>{$cssVars|noescape}</style>{/if}

    <link rel="stylesheet" href="{$theme->url}/assets/css/style.css">

    {* User-supplied custom CSS — always loaded last so it wins. *}
    {var $customCss = $theme->setting('advanced.custom_css', '')}
    {if $customCss}<style>{$customCss|noescape}</style>{/if}
</head>

{* Read settings once, project them onto body classes so CSS can branch. *}
{var $fontStyle = $theme->setting('typography.font_family', 'sans')}
{var $sidebar   = $theme->setting('layout.sidebar', 'right')}
<body class="{$body_class ?? ''} font-style--{$fontStyle} sidebar--{$sidebar}">
    {include 'partials/header.latte'}
    <main>{block content}{/block}</main>
    {include 'partials/footer.latte'}
</body>
</html>
```

### 3.3 Slots and components

Render configurable regions with `{slot('name')}`. Render a single named
component with `{component('type', [params])}`:

```latte
<aside class="sidebar">
    {=slot('sidebar')|noescape}
</aside>

<aside class="sidebar-nav">
    {=component('menu', ['location' => 'header'])|noescape}
</aside>
```

The `location` key passed to `{component('menu', ...)}` **must** match
a key declared under the `menus` block of `theme.json` (§2.4). That
declaration is how the admin discovers which navigation regions exist —
referencing an undeclared key will render an empty menu. See §2.4 for
why you usually reach for `$site->menu('location')` instead.

`|noescape` is required because both helpers return pre-rendered,
already-escaped HTML.

### 3.4 Partials — the idiomatic pattern

Breadcrumbs, pagination, and navigation are data arrays (§3.1) that
TypeDock hands to every template. The convention is to isolate their
HTML in `partials/` and pull them in wherever needed:

```
partials/
  header.latte       # consumes $site->menu('header')
  footer.latte       # consumes $site->menu('footer')
  breadcrumb.latte   # consumes $breadcrumbs
  pagination.latte   # consumes $pagination
```

Then each layout stays focused on its page shape:

```latte
{* layouts/single.latte *}
{layout 'base.latte'}
{block content}
    {include 'partials/breadcrumb.latte'}
    <article>
        <h1>{$page->title}</h1>
        {$page->renderedBody|noescape}
    </article>
{/block}
```

```latte
{* layouts/archive.latte *}
{layout 'base.latte'}
{block content}
    {include 'partials/breadcrumb.latte'}
    {foreach $pages as $item}
        <article><a href="{$item->url}">{$item->title}</a></article>
    {/foreach}
    {include 'partials/pagination.latte'}
{/block}
```

This keeps the HTML for shared UI concerns in one place and makes
layouts easy to skim. Use it over inlining the `<nav>` and `<ol>` on
every layout.

### 3.5 Reading a setting

```latte
<body class="... font-style--{$theme->setting('typography.font_family', 'sans')}">
```

Always pass a default as the second argument. Saved values are merged
with the schema's defaults, but defending against a missing key is
cheaper than debugging a `null` in the class list later.

---

## 4. Core design principle: bare components, themed chrome

**Components render the smallest useful semantic HTML.** Themes decide
the chrome — cards, borders, spacing, colors.

This rule is what lets components be placed into any slot — sidebar,
footer column, header-right, a custom after_content region — without the
component fighting the surrounding context.

### 4.1 What components emit

A well-behaved component template looks like this:

```latte
{* search-form.latte — emitted by core *}
<form class="search-form" action="{$action}" method="get" role="search">
    <label for="search-input" class="sr-only">Search</label>
    <input type="search" id="search-input" name="q"
           value="{$query}" placeholder="{$placeholder}">
    <button type="submit" class="search-submit" aria-label="Search">...</button>
</form>
```

No card. No padding. No background. Just the form and its role.

Other built-ins follow the same pattern:

- `latest_posts` → `<ul class="post-list"><li>…</li></ul>`
- `category_list` → `<ul class="category-list">…</ul>`
- `menu` → `<ul class="menu-list">…</ul>`
- `link_list` → `<nav class="link-list">…</nav>`

Each component carries a stable class on its root element so themes can
target it. Beyond that, nothing.

### 4.2 What the theme does

The theme applies chrome **scoped to the slot**. Example from Kinari's
stylesheet:

```css
/* Every direct sidebar child becomes a card — consistent widths,
   consistent padding, regardless of which component landed there. */
.sidebar > * {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 1.25rem;
}

/* Header slot is compact and transparent — reset anything the
   component template brought in, then size it for the header. */
.site-header-actions { width: 220px; }
.site-header-actions > * {
    background: transparent;
    border: 0;
    padding: 0;
}

/* Footer columns: clear background, no border. */
.footer-column > .widget,
.footer-column > ul,
.footer-column > div {
    background: transparent;
    border: 0;
    padding: 0;
}
```

The same `<form class="search-form">` element renders as a padded card in
the sidebar, a compact icon group in the header, and a bare form in the
footer — all driven by the theme's slot-scoped selectors.

### 4.3 Why this matters

- **Portability.** Any component (core, module, plugin) works in any
  slot your theme declares, without the theme author having to style
  every combination.
- **Accessibility utilities ship with the theme.** Things like
  `.sr-only` / `.skip-link` are theme responsibilities — components
  just use the classes assuming they exist. Declare them in your theme
  stylesheet.
- **Plugin compatibility.** A third-party `sns_links` component you've
  never seen will still look correct because the chrome comes from
  your slot CSS, not from the plugin.

### 4.4 Convention: mandatory utility classes

Every theme MUST define:

```css
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.skip-link { /* visible on :focus, invisible otherwise */ }
```

Components assume these exist. The component API treats them as part of
the theme contract — just like `<body>` existing or CSS being loaded.

---

## 5. Theme settings in practice

### 5.1 CSS custom properties

Every scalar setting is projected onto a CSS custom property named
`--td-<group>-<field>` (hyphen-separated, lowercased). So this schema:

```json
"settings": {
  "colors": { "fields": { "accent": { "type": "color", "default": "#8c2a3a" } } },
  "layout": { "fields": { "content_width": { "type": "select", "default": "normal" } } }
}
```

…is rendered by `$themeStyle->renderCssVariables()` as:

```css
:root {
    --td-colors-accent: #8c2a3a;
    --td-layout-content-width: normal;
}
```

`textarea` and `image` fields are skipped — they can't be scalar CSS
values. `boolean` is skipped too (CSS can't meaningfully express it).

**Values are emitted verbatim.** The core does not know what `normal`
means; your theme does. The pattern is:

```css
/* Alias --td-* variables to the names used through the stylesheet. */
:root {
    --color-accent: var(--td-colors-accent, #2563eb);
    --content-width: 780px;  /* fallback */
}

/* Branch on the setting value via body classes (base.latte appends
   `width--<key>` to the body based on the setting). */
body.width--narrow { --content-width: 680px; }
body.width--normal { --content-width: 780px; }
body.width--wide   { --content-width: 960px; }
```

This split keeps the core free of any vocabulary — no "narrow means 680px"
mapping in PHP — while letting your theme express the semantics however
it wants.

### 5.2 Switching assets per setting

For settings that need to change more than just colour — different
Google Fonts, different layout files — branch in `base.latte` before
emitting `<link>` tags:

```latte
{var $fontStyle = $theme->setting('typography.font_family', 'sans')}

{if $fontStyle === 'sans'}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
{elseif $fontStyle === 'serif'}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..700&display=swap">
{/if}
```

Only load what you need — every extra family is bytes on the critical
path and a licence surface.

### 5.3 The custom CSS escape hatch

If your theme ships with (say) three font styles, a user who wants a
fourth — a Japanese operator who wants Noto Sans JP, say — has two
options:

1. **Override via custom CSS.** Declare an `advanced.custom_css` field
   of type `textarea`. Emit its content as a `<style>` block in
   `base.latte`, **after** your theme stylesheet, so it always wins:

   ```latte
   {var $customCss = $theme->setting('advanced.custom_css', '')}
   {if $customCss}<style>{$customCss|noescape}</style>{/if}
   ```

   The user can then paste, e.g., `@import url(...Noto+Sans+JP...);
   body { font-family: 'Noto Sans JP', sans-serif; }`.

2. **Fork the theme.** Copy it, add a fourth preset, distribute.

The first is the recommended pattern for site-level tweaks. The second
is the right answer when the theme needs real structural changes.

---

## 6. Assets

### 6.1 Publishing

`public/themes/<slug>/assets/` is populated by `AssetPublisher` on theme
activation and whenever `php cli/assets-publish.php` is run. The source
lives at `themes/<slug>/assets/`, and templates reference the published
URL via `{$theme->url}/assets/...`.

During development you can skip the CLI and just mirror your source dir:

```bash
php cli/assets-publish.php   # publishes all themes + plugins
```

### 6.2 The `screenshot.svg` convention

Drop `assets/screenshot.svg` (PNG / JPG / WEBP also supported) and it
will be shown as the theme's preview on `/admin/themes`. Recommended
size: 1200×900 @ 2x.

### 6.3 Third-party assets

Theme-supplied JS is loaded with `defer`. Keep it framework-free when
possible — TypeDock's admin already ships no JS framework on the
frontend, so adding one just for a menu toggle goes against the grain.

---

## 7. Context-type awareness

Slots can declare the page contexts they render on, via the `context`
array. Components can declare the contexts they **support**, via their
PHP definition. TypeDock intersects the two:

- A component placed in a slot whose context it doesn't support is
  flagged in the admin UI and skipped at render time.
- The admin "add component" dropdown hides incompatible options.

As a theme author you don't have to worry about this — just set
`"context": ["post", "page"]` on slots that only apply to single
content and let the rest default to `["all"]`.

---

## 8. A checklist for shipping a new theme

Before you publish a theme on a marketplace or submit it to the
TypeDock theme repository:

- [ ] `theme.json` has `name`, `version`, `author`, `description`
- [ ] At least `base`, `single`, `page`, `archive`, `search`, `404`
      layouts render without error against a seeded database
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
      dynamic flows through `{component}` or `{slot}`
- [ ] Switching between `font-style--sans` / `--serif` etc. does not
      leave stray `var(--font-serif)` references unset
- [ ] `screenshot.svg` (or .png/.jpg/.webp) is present under `assets/`
- [ ] `advanced.custom_css` (or equivalent) is exposed so users can
      override per-locale needs without forking

---

## 9. Further reading

- `themes/kinari/` — reference theme, reviewed alongside this guide
- `themes/default/` — the minimal starting point
- TypeDock component API — documented separately under the core repo
- TypeDock module API — for building new components that plug into
  themes via slots
