# Bare Components, Themed Chrome

The component contract themes target — what core/plugin components
emit, and what the theme is responsible for adding around them.

For the template syntax that invokes components (`{component(...)}`,
`{slot(...)}`) see
[theme-template-reference.md](theme-template-reference.md). For
introductory orientation see
[theme-development.md](theme-development.md).

---

## The principle

**Components render the smallest useful semantic HTML.** Themes decide
the chrome — cards, borders, spacing, colors.

This rule is what lets components be placed into any slot — sidebar,
footer column, header-right, a custom after_content region — without the
component fighting the surrounding context.

---

## 1. What components emit

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

Plugin components follow the same contract. They may ship admin UI CSS
for their iframe settings screens, but their **frontend** output should
stay semantic and minimally styled. The active theme owns the visible
chrome.

Common bundled plugin component classes:

| Plugin | Frontend classes themes should expect |
|--------|---------------------------------------|
| Form | `.td-form`, `.td-form-field`, `.td-form-success`, `.td-form-error`, `.td-form-required`, `.td-form-submit`, `.td-form-thanks` |
| Social | `.td-social-share`, `.td-social-share-list`, `.td-social-share-item`, `.td-social-share-copy`, `.td-social-follow`, `.td-social-follow-list`, `.td-social-follow-item` |

---

## 2. What the theme does

The theme applies chrome. Prefer slot-scoped rules for furniture that
changes by placement, and component-class rules for base affordances
that should remain recognizable anywhere.

Example from Kinari's stylesheet:

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

For plugin components, keep the same split. The theme can style the
basic control states once, then let slot rules decide whether the whole
component sits in a card, a footer column, or inline content:

```css
/* Base affordance: inputs and submit buttons should be usable anywhere. */
.td-form input,
.td-form textarea,
.td-form select {
    border: 1px solid var(--color-border);
    border-radius: 6px;
    padding: 0.65rem 0.75rem;
}

.td-form-submit,
.td-social-share a,
.td-social-share-copy,
.td-social-follow a {
    background: var(--color-accent);
    color: var(--color-on-accent);
    border-radius: 6px;
}

/* Layout: the slot decides how the component fits the page. */
.sidebar > .td-form,
.sidebar > .td-social-share,
.sidebar > .td-social-follow {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    padding: 1.25rem;
}
```

---

## 3. Why this matters

- **Portability.** Any component (core or plugin) works in any
  slot your theme declares, without the theme author having to style
  every combination.
- **Accessibility utilities ship with the theme.** Things like
  `.sr-only` / `.skip-link` are theme responsibilities — components
  just use the classes assuming they exist. Declare them in your theme
  stylesheet.
- **Plugin compatibility.** A third-party `sns_links` component you've
  never seen will still look correct because the chrome comes from
  your slot CSS, not from the plugin.
- **Theme settings compatibility.** Plugin components automatically
  inherit the site's colours, borders, spacing, and type scale when
  their chrome uses the theme's semantic CSS variables.

---

## 4. Frontend CSS responsibility for plugins

Frontend CSS for plugin components belongs in themes, not in plugin
assets, unless the CSS is purely functional and cannot reasonably be
owned by a theme. A plugin may provide stable classes, ARIA attributes,
data attributes, and minimal structure. It should not impose cards,
brand colours, large spacing, shadows, or typography on the public site.

Bundled themes should style bundled plugin components well enough that
enabling a plugin never creates an unstyled first-run experience. Third
party themes should at least cover stable classes from the components
they expect site operators to use, especially forms and social links.

---

## 5. Convention: mandatory utility classes

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
