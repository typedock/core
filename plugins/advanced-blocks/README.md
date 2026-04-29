# Advanced Blocks

Adds extra component blocks to the body editor's slash menu.

## Blocks

### Callout

Highlighted info / warning / success / danger box. Optional title above the body. Insert via the editor's slash menu and pick a variant in the parameter panel.

### Balloon (speech bubble)

Speaker icon with a speech bubble for tutorial / interview-style content. Choose left- or right-facing, plus speaker name and avatar URL.

### CTA button

Prominent call-to-action button with optional caption. Pick from three styles (primary / secondary / outline), three sizes, and three alignments. Set "Open in new tab" for outbound links. Leaving the URL empty renders a disabled placeholder so a half-finished block is visually obvious in preview.

## Theming

Both blocks emit semantic markup with stable class names (`td-callout`, `td-balloon`) and BEM-style modifiers. The plugin ships minimal default styles — themes are expected to layer their own CSS via the standard `themes/<slug>/assets/css/style.css` (see [theme development guide](../../docs/theme-development.md)) or override the templates wholesale at `themes/<slug>/components/{callout,balloon}.latte`.

## Permissions

These blocks render whatever text is entered in their `body` param. They escape the input through Latte's auto-escape and use `|breakLines` to convert newlines to `<br>` — no raw HTML is permitted. Anyone with `posts:edit_own` or `pages:edit_own` can insert them.
