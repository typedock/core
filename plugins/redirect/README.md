# Redirect

Create HTTP redirects from old URLs to new destinations.

## Exact redirects

Use a plain source path for exact matching:

- Source: `/old-page`
- Target: `/new-page`

## Regex redirects

Prefix the source with `~` to enable regular expression matching:

- Source: `~^/old/(.*)$`
- Target: `/new/$1`

Capture groups can be reused in the target as `$1`, `$2`, and so on.

Use regex rules sparingly. Put exact redirects in first when possible because they are easier to audit.

## Importing in bulk

Upload a CSV or JSON file to add many rules at once. The `redirects-<id>.csv` file that
Tools → Import offers after a content migration works as-is.

CSV, with or without the header row:

```csv
from,to,status
/old-page,/new-page,301
/legacy/docs,https://docs.example.com/,308
```

JSON, as an array of objects:

```json
[
  { "from": "/old-page", "to": "/new-page", "status": 301 }
]
```

Details:

- `status` is optional and defaults to `301`. Only 301, 302, 307 and 308 are accepted.
- `source`/`source_path` and `target`/`target_url` are accepted as column and key names too.
- A source given as a full URL (`https://old.example.com/page`) is reduced to its path,
  since rules match against the request path.
- A rule whose source path already exists is updated, not duplicated — uploading the same
  file twice leaves the same set of rules.
- Rows that cannot be used (missing column, unsupported status, a regex that does not
  compile) are skipped and reported instead of being stored as rules that never fire.
