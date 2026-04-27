# Latte 3 Quick Reference for Theme Authors

TypeDock templates use [Latte 3](https://latte.nette.org/en/syntax),
which has subtle but breaking differences from Latte 2 (and from
`{...}`-based template syntaxes generally). This page collects the
sharp edges most theme authors hit at least once.

For variable shapes and the `$page` / `$post` view models see
[theme-template-reference.md](theme-template-reference.md).

---

## 1. `{literal}` is gone — use `{syntax off}` or `{l}` / `{r}`

Latte 2's `{literal}…{/literal}` block was removed. The replacement is
either a syntax switch or escape sequences:

```latte
{* Latte 2 (no longer compiles) *}
{literal}
function greet(name) { return `Hello ${name}`; }
{/literal}

{* Latte 3 — switch syntax off for the block *}
{syntax off}
function greet(name) { return `Hello ${name}`; }
{/syntax}

{* Latte 3 — single-character escapes *}
config = {l} foo: 1, bar: 2 {r}
```

`{l}` produces `{` and `{r}` produces `}` — handy for inline JSON-like
fragments or documenting Latte syntax inside Latte.

---

## 2. `<script>` blocks need `n:syntax="off"`

Modern JS template literals (`` `${var}` ``) collide with Latte's `{…}`
tag opener. By default Latte will try to parse `${var}` as an output
tag and throw a `CompileException`. Mark every `<script>` with the
template-literal pattern as syntax-off:

```latte
<script n:syntax="off">
const greet = (name) => `Hello, ${name}`;
</script>
```

Inline `<script>` tags that don't use template literals are safe to
leave alone, but `n:syntax="off"` is cheap insurance and convention in
the bundled themes.

---

## 3. URL escaping is `|escapeUrl`

`|url` and `|urlencode` are *not* Latte filters. Use `|escapeUrl` for
query-string interpolation:

```latte
<a href="https://twitter.com/intent/tweet?url={$shareUrl|escapeUrl}&text={$page->title|escapeUrl}">
    Share
</a>
```

For raw HTML you've already pre-escaped (`$page->renderedBody`,
`$seo->jsonLd`, slot/component output), use `|noescape`:

```latte
{$page->renderedBody|noescape}
```

For everything else, **don't add a filter** — Latte auto-escapes by
default, which is the entire point of using it.

---

## 4. `{include}` uses named arguments

```latte
{include 'partials/post-card.latte', post: $post, variant: 'archive'}
```

Note the `:` separator (PHP-named-argument style), not `=` or `=>`.

Prefer `{include}` over `{define}` — `{define}` exists but reuses the
parent template's variable scope in surprising ways. Splitting into a
fresh `partials/<name>.latte` is the idiomatic pattern.

---

## 5. `{foreach}` iterator variables

Latte exposes `$iterator` inside every `{foreach}` block:

```latte
{foreach $posts as $post}
    <article class="post-card {$iterator->isFirst() ? 'is-first' : ''}">
        <span class="card-index">{sprintf('%02d', $iterator->counter)}</span>
        <h3>{$post->title}</h3>
    </article>
{/foreach}
```

| Property            | Meaning                                |
|---------------------|----------------------------------------|
| `$iterator->counter` | 1-indexed loop counter                |
| `$iterator->isFirst()` | true on the first iteration         |
| `$iterator->isLast()`  | true on the last iteration          |
| `$iterator->odd` / `even` | bool, alternates                 |

This is the idiomatic way to alternate row classes, build numbered
lists, or skip the first entry. Avoid hand-rolled `$i = 0; $i++`
counters via `{var}` — they work but read worse.

---

## 6. Conditional output is `{if}` … `{else}` … `{/if}`

```latte
{if $post->thumbnail}
    <img src="{$post->thumbnail}" alt="{$post->thumbnailAlt}">
{elseif $theme->setting('show_placeholder', false)}
    <span class="post-thumb-placeholder"></span>
{/if}
```

`n:if` is the inline attribute form for tags you want to wrap
conditionally:

```latte
<a n:if="$post->thumbnail" href="{$post->url}">
    <img src="{$post->thumbnail}" alt="{$post->thumbnailAlt}">
</a>
```

---

## 7. WordPress conditional tags don't exist — use template selection

WordPress themes branch on `is_single()`, `is_archive()`,
`is_category('news')`, etc. TypeDock instead **selects a different
template file** for each context: `single.latte`, `page.latte`,
`archive.latte`, `search.latte`, `home.latte`, `author.latte`. Most
"is this a single post?" branches in a WordPress theme become a
separate `.latte` file in TypeDock.

For the *intra-template* branches that remain (e.g. "in `single.latte`,
show a hero only for posts in the `news` category"), the available
signals are:

| Signal                  | When useful                                 |
|-------------------------|---------------------------------------------|
| `$body_class`           | String of route classes (`single single-post`, `archive category-news`, `home archive blog-archive`). Branch with `str_contains()` for ad-hoc cases. |
| `$page->postType`       | `'post'` vs `'page'` inside `single.latte` / `page.latte`. |
| `$page->category`       | Primary category on a single post (or null). |
| `isset($category)`      | Inside `archive.latte`, true on category archive (vs tag/blog). |
| `isset($tag)`           | Inside `archive.latte`, true on tag archive. |
| `isset($query)`         | Inside `search.latte`, the search query string. |
| `isset($page)`          | In `home.latte`, true when home mode is `page`. |

Body classes emitted by core today:

| Body class            | Where     |
|-----------------------|-----------|
| `home`                | `/` root  |
| `archive`             | any list view (blog index, category, tag, author) |
| `blog-archive`        | the bare blog index |
| `category-archive`    | category archive |
| `category-<slug>`     | category archive (slug-specific) |
| `tag-archive`         | tag archive |
| `tag-<slug>`          | tag archive |
| `author-archive`      | author archive |
| `author-<slug>`       | author archive |
| `single`              | single post |
| `single-post`         | single post (also added under `/`-as-single-post mode) |
| `page`                | static page |
| `search-page`         | search results |

---

## 8. Common gotchas

- **`{= ... }` vs `{ ... }`.** The `=` form was historically required
  for output but is now optional and discouraged. Write `{$page->title}`,
  not `{=$page->title}`. The exception is when chaining filters that
  aren't auto-escape-safe (rare in themes).
- **Quoting attribute values.** Latte safely interpolates inside HTML
  attributes — `<a href="{$post->url}">` is fine. Don't add manual
  `htmlspecialchars()`.
- **`{php}` blocks.** Don't use them in themes. If you find yourself
  reaching for one, the dynamic value belongs in a fetch source
  declaration in `theme.json` (see
  [theme-json-reference.md](theme-json-reference.md) §5).
- **Whitespace control.** Latte trims whitespace around tags by
  default. Use `{contentType ...}` declarations only when emitting
  non-HTML responses (rare in themes).
