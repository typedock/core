# WordPress Importer

Imports posts, pages, categories and tags from a WordPress export file (WXR).

```bash
# See what the file contains — writes nothing
php cli/import.php --importer=wordpress export.xml --dry-run

# Import
php cli/import.php --importer=wordpress export.xml

# Import everything as drafts so you can review before publishing
php cli/import.php --importer=wordpress export.xml --as-draft
```

Gzipped exports (`export.xml.gz`) work without unpacking them first.

## What comes across

| WordPress | TypeDock |
|---|---|
| Posts, pages | Posts, pages |
| Categories (with hierarchy), tags | Categories, tags |
| `publish` / `future` / `pending` / everything else | published / scheduled / review / draft |
| Author | Matched to an existing account by email address |
| Page parents | Resolved after the import, so a child listed before its parent still links up |
| Hand-written excerpts | Excerpt (auto-generated "[…]" ones are skipped) |

Trashed posts, attachments and custom post types are not imported. Neither are
comments, custom fields, menus or users — the same line other CMS migration
tools draw.

## What does not come across yet

**Images still point at your WordPress site.** Downloading them into the media
library is not implemented, so keep the old site online for now.

Absolute links back to the old site are left as they are. The dry run tells
you how many there are.

## Re-running is safe

Every post is matched on its WordPress post ID, so importing the same file
twice updates the existing content instead of duplicating it. Imports do not
create revisions, so re-running will not bury your editing history.

To undo an import completely, delete the rows it created:

```sql
DELETE FROM posts WHERE import_batch_id = '<the id printed at the end>';
```

## Content that cannot become blocks

Anything the converter does not understand — tables, embeds, arbitrary markup
from old plugins — is preserved verbatim in a Custom HTML block and counted in
the dry run. Nothing is dropped silently. `<script>` and `<style>` are the
exception: they belong to the old theme, not to your content, and are removed.

## Requirements

PHP's `xmlreader` extension. Without it the plugin reports the problem under
**Settings → Modules → Plugin load diagnostics** rather than half-working.
