# Theme Settings in Practice

How theme settings declared in `theme.json` reach your CSS, and the
patterns themes use to keep them maintainable.

For the `settings` block declaration syntax (groups, field types,
defaults) see
[theme-json-reference.md](theme-json-reference.md). For introductory
orientation see [theme-development.md](theme-development.md).

---

## 1. CSS custom properties

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
    --color-on-accent: #ffffff;
    --color-border: #e5e7eb;
    --color-surface: #ffffff;
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

Use those semantic aliases throughout your component and plugin
component chrome. Do not hard-code a second design system for `.td-form`
or `.td-social-*`; let those selectors inherit the same tokens as the
rest of the theme. That keeps Form, Social, and future plugin components
in sync with `/admin/theme-settings`.

---

## 2. Switching assets per setting

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

---

## 3. The custom CSS escape hatch

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
