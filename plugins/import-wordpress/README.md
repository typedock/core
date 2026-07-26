# WordPress Importer

Imports posts, pages, categories and tags from a WordPress export file (WXR).

The usual way in is **Import** in the admin sidebar: it reports what the file
contains before writing anything, shows progress, and can undo the whole
import afterwards. Everything below is the command-line equivalent.

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
| Inline images | Downloaded into the media library, thumbnails and all |

Trashed posts, attachments and custom post types are not imported. Neither are
comments, custom fields, menus or users — the same line other CMS migration
tools draw.

## Images

Images found in post bodies are copied into the media library, resized and
given the same thumbnails and WebP siblings as an ordinary upload. Each image
is fetched once however many posts use it, and WordPress's `-300x200` size
suffixes are normalised away so you get the original.

Downloads run in the background: a large site keeps fetching after the import
command finishes. Run `php cli/queue-work.php` (or set up cron — see the main
README) to work through the queue.

An image that cannot be fetched after several tries is left pointing at your
old site rather than at a broken link, and the media row keeps the source URL
so you can retry or upload a replacement.

Pass `--skip-media` to keep every image on the old site instead.

## Links and redirects

Links between posts you imported are rewritten to point at their new homes.
This happens after the last item lands, because only then is it known whether
a link's target became a post or a page, and what slug it ended up with.

Links to pages you did *not* import stay as they are — the dry run counts
them.

When the import finishes, download the **redirect map** from the progress
page: a CSV of every old URL and where it lives now, ready for your web
server, CDN, or the Redirect plugin.

## What does not come across yet

The featured image (`_thumbnail_id`) is not migrated.

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
