# TypeDock Template Reference

Everything a theme template can read at render time, plus the idiomatic
Latte patterns themes use to assemble pages.

For an introduction to themes (directory layout, `theme.json`,
publishing, the shipping checklist) start with
[theme-development.md](theme-development.md).

---

## 1. The Latte globals available to every template

This is the **complete** list of variables and properties TypeDock hands
to your templates. Anything not in these tables does **not** exist on the
view model — referencing it (e.g. `$page->categories[0]` on the wrong
context) will fall through to `null` at render time, which Latte 3 surfaces
as a warning. AI-generated themes are particularly prone to inventing
fields that "ought to be there"; treat this section as canonical.

### 1.1 Always-available globals

| Variable        | Type                            | Purpose |
|-----------------|---------------------------------|---------|
| `$site`         | SiteService                     | `$site->name`, `$site->url`, `$site->option('site.description')`, `$site->postUrl()`, `$site->postsArchiveLabel` |
| `$site->menu()` | `fn(string): MenuItem[]`        | `$site->menu('header')` returns the tree of menu items for the given location. URLs are resolved for Page/Post/Category targets. See `menus` in [theme-json-reference.md](theme-json-reference.md). |
| `$theme`        | ThemeContext                    | `$theme->url` (public assets root), `$theme->name`, `$theme->setting('group.field', default)` |
| `$themeStyle`   | ThemeStyleRenderer              | `$themeStyle->renderCssVariables()` — emit `--td-*` declarations |
| `$cms`          | feature-detection helper        | `$cms->hasModule('Collection')`, `$cms->hasModule('Backup')` |
| `$body_class`   | string                          | Route-provided body class |
| `$currentUrl`   | string                          | Current request URI (path + query). Useful for `<link rel="canonical">` fallbacks and "active nav" checks. |
| `$fetch`        | object                          | Per-template fetch results from `theme.json` `templates.<name>.fetch`. Each declared key becomes a property whose value is the data source's return type — `PostView[]` for `source: posts` / `related_posts`, an array of `{id, name, slug, post_count}` for `categories` / `tags`. Absent keys are `null` (not undefined). |

### 1.2 Per-context globals

| Variable       | When present                                        | Notes |
|----------------|-----------------------------------------------------|-------|
| `$breadcrumbs` | Single, page, archive (category/tag/author), search | Empty array on 404/500/home. Each item has `label` (string), `url` (string), `isCurrent` (bool). Render via `{include 'partials/breadcrumb.latte'}`. |
| `$pagination`  | archive, search, author archive, home (archive mode) | `PaginationData`: `current`, `totalPages`, `perPage`, `totalItems`, plus `hasPrev()`, `hasNext()`, `url(int $page)`, `range(int $window)`. `range()` returns a contiguous list of page numbers — no `null` gaps. |
| `$seo`         | single, page, home                                  | `SeoService` result object: `title`, `description`, `canonical`, `robots`, `ogTitle`, `ogDescription`, `ogImageUrl`, `ogType`, `twitterCard`, `schemaType`, `jsonLd` (HTML string, emit with `\|noescape`). |
| `$page`        | single (`single.latte`), page (`page.latte`), home in `home.latte` when `home_mode = page` | `PageView` object — see §2. |
| `$posts`       | archive, author archive, home (archive mode)        | `PostView[]` (list) — see §3. |
| `$results`     | search                                              | `PostView[]` (list). Same shape as `$posts`. |
| `$category`    | category archive                                    | Array `{id, name, slug, description?, parent_id?}`. |
| `$tag`         | tag archive                                         | Array `{id, name, slug}`. |
| `$author`      | author archive                                      | Array `{id, name, display_name, slug, bio?, website_url?, avatar_url?, social_links}`. |
| `$query`       | search                                              | string — the raw query the visitor typed. |

---

## 2. `$page` — the single-page view model

Available on `single.latte`, `page.latte`, and `home.latte` when home
mode is `page`. `PageView` is a strict superset of `PostView` (§3).

| Property                | Type                | Notes |
|-------------------------|---------------------|-------|
| `$page->id`             | string (UUID)       | |
| `$page->slug`           | string              | |
| `$page->title`          | string              | |
| `$page->url`            | string              | Full URL with origin. |
| `$page->excerpt`        | string              | Auto-derived from body if not authored. |
| `$page->publishedAt`    | ?string (ISO8601)   | `null` for never-published rows. |
| `$page->updatedAt`      | ?string (ISO8601)   | |
| `$page->postType`       | `'post'` \| `'page'` | |
| `$page->status`         | string              | `'published'` for any rendered page. |
| `$page->thumbnail`      | ?string (URL)       | The page's image (per-page `og_image`, falling back to the site-wide default). Use for cards / list-view contexts. |
| `$page->heroImage`      | ?string (URL)       | Same value as `$page->thumbnail`. Use this name in single/hero contexts so the template intent reads correctly — both fields share one underlying media id today. |
| `$page->thumbnailAlt`   | string              | The image's alt text from the media library (`media.alt_text`). Empty string when not set or when there's no image. |
| `$page->ogImageUrl`     | ?string (URL)       | Alias for `$page->thumbnail`. Matches `$seo->ogImageUrl`. |
| `$page->renderedBody`   | string (HTML)       | Pre-rendered Tiptap → HTML. Always emit with `\|noescape`. |
| `$page->author->name`   | ?string             | `display_name` if set, else `name`. |
| `$page->author->slug`   | ?string             | `null` for system / external authors. |
| `$page->author->avatar` | ?string (URL)       | From `users.avatar_media_id` (uploaded), then `users.avatar_path` (URL fallback). |
| `$page->author->bio`    | ?string             | |
| `$page->author->websiteUrl` | ?string         | |
| `$page->category`       | ?{name, slug}       | Primary category — first by `categories.sort_order`. `null` when no category attached. Convenient for cards / kickers. |
| `$page->categories`     | array of {id, name, slug} | All categories attached to this page. Empty array when none. |
| `$page->tags`           | array of {id, name, slug} | All tags. Empty array when none. |

**Properties that intentionally do not exist on `$page`:**
`readingTime` (TypeDock does not estimate read time — compute it in your
template if you need it), `commentCount` (no native comments yet), and
any author social links beyond `websiteUrl`.

---

## 3. `$posts` / `$results` — the list view model (`PostView`)

The shape themes consume on every list-driven layout (`archive.latte`,
`author.latte`, `search.latte`, `home.latte` in archive mode), as well
as inside core component templates (`latest_posts`, `related_posts`),
and as the elements of any `theme.json` `fetch` declaration whose
`source` is `posts` or `related_posts`.

| Property                | Type                | Notes |
|-------------------------|---------------------|-------|
| `$post->id`             | string (UUID)       | |
| `$post->slug`           | string              | |
| `$post->title`          | string              | |
| `$post->url`            | string              | Full URL. Use this — never reconstruct via `$site->postUrl($post->slug)` or `post_path()`. |
| `$post->excerpt`        | string              | Authored excerpt, or auto-derived. |
| `$post->publishedAt`    | ?string (ISO8601)   | |
| `$post->updatedAt`      | ?string (ISO8601)   | |
| `$post->postType`       | `'post'` \| `'page'` | |
| `$post->thumbnail`      | ?string (URL)       | The post's image (per-row `og_image`, falling back to the site-wide default). |
| `$post->heroImage`      | ?string (URL)       | Same value as `$post->thumbnail` — kept as a separate name so list-view templates can read `thumbnail` and feature/hero templates can read `heroImage`. |
| `$post->thumbnailAlt`   | string              | Alt text from `media.alt_text`. Empty string when no image / no alt set. Always pair with the image: `<img src="{$post->thumbnail}" alt="{$post->thumbnailAlt}">`. |
| `$post->author->name`   | ?string             | |
| `$post->author->slug`   | ?string             | |
| `$post->category`       | ?{name, slug}       | Primary category for "category overlay" labels. `null` when the post has no category. |

**Properties that intentionally do not exist on `$post` in lists:**
`renderedBody` (lists never need full body — query the single page if
you do), `categories` / `tags` (lists carry only the *primary* category
to keep the view model cheap; query a fetch source if you need the full
list per card), `author->avatar` / `bio` (those are loaded for `$page`
only).

### 3.1 Old array-style access is gone

Earlier prototypes exposed posts as PHP arrays (`$post['title']`,
`$post['og_image_url']`, `$post['author_name']`). All themes now consume
the object shape above. Translation table for porting an older theme:

| Old (array)                              | New (object)                  |
|------------------------------------------|--------------------------------|
| `$post['id']`                            | `$post->id`                    |
| `$post['title']`                         | `$post->title`                 |
| `$post['slug']`                          | `$post->slug`                  |
| `$post['og_image_url']`                  | `$post->thumbnail`             |
| `$post['published_at']`                  | `$post->publishedAt`           |
| `$post['updated_at']`                    | `$post->updatedAt`             |
| `$post['excerpt']`                       | `$post->excerpt`               |
| `$post['author_name']`                   | `$post->author->name`          |
| `$post['category_name']` *(never existed)* | `$post->category?->name`     |
| `$site->postUrl($post['slug'])`          | `$post->url`                   |
| `post_path($post['slug'])`               | `$post->url`                   |

---

## 4. Image handling

There is one image per page in TypeDock today: the `og_image` set on the
SEO panel. The view model exposes it under three names so templates can
read whichever fits the context:

| Name              | When to use it                                                                |
|-------------------|-------------------------------------------------------------------------------|
| `$post->thumbnail` | Cards / list rows / sidebar widgets                                          |
| `$post->heroImage` | Hero / featured / above-the-fold contexts on `single.latte` or `home.latte`  |
| `$post->ogImageUrl` (single only) | Anything tied to social sharing meta — same value as `$seo->ogImageUrl` |

All three resolve from the same media id, so set the SEO image once and
every consumer picks it up. `$post->thumbnailAlt` is the alt text from
`media.alt_text` (empty string when not authored). Always pair them:

```latte
{if $post->thumbnail}
    <img src="{$post->thumbnail}" alt="{$post->thumbnailAlt}" loading="lazy">
{/if}
```

**Fallback strategy.** When a post has no image, `$post->thumbnail` is
`null`. Themes decide what to do:

```latte
{* Option 1 — graceful: hide the image entirely. *}
{if $post->thumbnail}
    <img src="{$post->thumbnail}" alt="{$post->thumbnailAlt}" loading="lazy">
{/if}

{* Option 2 — slot a placeholder so cards stay the same shape. *}
<img src="{$post->thumbnail ?: $theme->url . '/assets/img/post-placeholder.svg'}"
     alt="{$post->thumbnailAlt}" loading="lazy">

{* Option 3 — for demos / dogfood / preview seeds only. *}
<img src="{$post->thumbnail ?: 'https://picsum.photos/seed/' . $post->slug . '/520/360'}"
     alt="" loading="lazy">
```

Option 1 is the production default. Option 3 is fine in bundled demo
themes (the Northline / Kawara theme files use it) but be explicit that
it's a demo crutch in your README so users know to author real images.

**What about srcset / multiple sizes?** Not yet. The contract today is
a single URL. When TypeDock adds responsive images, it will extend
`$post->thumbnail` to also expose sibling fields (`thumbnailSrcset`,
`thumbnailSizes`) — never replace the existing fields. Treat the URL
form as stable.

---

## 5. `base.latte` — the root document

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

---

## 6. Slots and components

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
a key declared under the `menus` block of `theme.json`. That declaration
is how the admin discovers which navigation regions exist — referencing
an undeclared key will render an empty menu. See
[theme-json-reference.md](theme-json-reference.md) for why you usually
reach for `$site->menu('location')` instead.

`|noescape` is required because both helpers return pre-rendered,
already-escaped HTML.

Important: `{component(...)}` and `{slot(...)}` are **theme template
syntax only**. Page and post bodies are stored as Tiptap JSON, so site
operators should insert components with the editor's slash menu
Component Block. Do not tell users to paste Latte tags into editor
content.

---

## 7. Partials — the idiomatic pattern

Breadcrumbs, pagination, and navigation are data arrays (§1) that
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
    {foreach $posts as $post}
        <article><a href="{$post->url}">{$post->title}</a></article>
    {/foreach}
    {include 'partials/pagination.latte'}
{/block}
```

This keeps the HTML for shared UI concerns in one place and makes
layouts easy to skim. Use it over inlining the `<nav>` and `<ol>` on
every layout.

---

## 8. Reading a theme setting

```latte
<body class="... font-style--{$theme->setting('typography.font_family', 'sans')}">
```

Always pass a default as the second argument. Saved values are merged
with the schema's defaults, but defending against a missing key is
cheaper than debugging a `null` in the class list later.

For how scalar settings turn into CSS custom properties (and the
recommended `--td-*` → semantic-token alias pattern), see
[theme-settings.md](theme-settings.md).
