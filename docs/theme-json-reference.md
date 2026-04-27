# `theme.json` Reference

The schema TypeDock reads from a theme's root `theme.json`. The file
serves three jobs:

- show the theme on `/admin/themes`,
- generate the `/admin/theme-settings` form,
- seed default slot placements on activation.

For introductory orientation see
[theme-development.md](theme-development.md). For how settings turn
into CSS variables, see [theme-settings.md](theme-settings.md).

---

## 1. Metadata

```json
{
  "name": "My Theme",
  "version": "1.0.0",
  "description": "What the theme is for, who it's aimed at.",
  "author": "Your name"
}
```

---

## 2. `settings` — user-tunable options

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

For the runtime side — how scalar settings turn into `--td-*` CSS
custom properties and the recommended `--color-*` alias pattern — see
[theme-settings.md](theme-settings.md).

---

## 3. `slots` — where content plugs in

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

---

## 4. `menus` — navigation locations

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

### 4.1 Naming

Name locations by **placement or role** (`header`, `footer`, `mobile`,
`utility`, `legal`). Avoid abstract names like `primary` or `nav1` —
the key is what the operator sees when editing, so make it describe
where the nav appears.

### 4.2 Rendering: `$site->menu()` is the primary pattern

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
| `targetType`   | string      | `custom` / `page` / `post` / `category` |
| `cssClass`     | ?string     | Optional per-item class (admin-set). Camel-cased on the `MenuItem` object — note the difference from `{component('menu', …)}`, which still hands back raw rows with the `css_class` array key. |
| `children`     | MenuItem[]  | One level of nesting is supported |

Menu items nest one level deep (`parent_id`). The admin UI enforces
this limit so your CSS only has to style two levels.

### 4.3 The `menu` component is a drop-in fallback

`{component('menu', ['location' => 'header'])}` still works and is
registered as a slot-placeable widget. Use it when you want to offer a
navigation as **sidebar furniture** or a similar widget slot. For the
primary header / footer / mobile navs that every theme has, prefer
`$site->menu()` — it gives you full control over markup without a
per-component template override.

### 4.4 Locale

Locale is stored internally but not surfaced in the admin UI — assume a
single-language site for now. When TypeDock grows first-class
multilingual support, the `menus` contract will not change; the admin
gains a language switcher on top.

---

## 5. `templates` — declarative data fetch

Each top-level layout (`home`, `single`, `archive`, `search`, `page`,
`author`) can declare a `fetch` block that loads data into the
template's `$fetch` global without writing PHP.

```json
{
  "templates": {
    "home": {
      "file": "layouts/home.latte",
      "fetch": {
        "home_posts": { "source": "posts", "params": { "limit": 12, "post_type": "post" }, "sort": "-published_at" },
        "home_categories": { "source": "categories", "params": { "show_empty": true }, "sort": "sort_order" }
      }
    },
    "single": {
      "file": "layouts/single.latte",
      "fetch": {
        "top_posts": { "source": "posts", "params": { "limit": 4 }, "sort": "-published_at" },
        "related":   { "source": "related_posts", "params": { "limit": 3 } },
        "tags":      { "source": "tags", "params": { "limit": 12, "order_by": "count" } }
      }
    }
  }
}
```

Available `source` values: `posts`, `related_posts`, `categories`,
`tags`, `menu`, `site_options`. The shape returned by each becomes the
value of `$fetch->{key}` in the template — see
[theme-template-reference.md](theme-template-reference.md) §1.1.

`params` may interpolate `{{context.category}}` / `{{context.tag}}` /
`{{context.post_id}}` etc. for archive / single contexts.

---

## 6. Context-type awareness

Slots can declare the page contexts they render on, via the `context`
array. Components can declare the contexts they **support**, via their
PHP definition. TypeDock intersects the two:

- A component placed in a slot whose context it doesn't support is
  flagged in the admin UI and skipped at render time.
- The admin "add component" dropdown hides incompatible options.

As a theme author you don't have to worry about this — just set
`"context": ["post", "page"]` on slots that only apply to single
content and let the rest default to `["all"]`.
