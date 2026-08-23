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

Use regex rules sparingly. Patterns are limited to 500 bytes, a site may
have at most 100 regex rules, and every pattern is evaluated with bounded PCRE
match/depth budgets. Put exact redirects in first when possible because they
are easier to audit.

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
- Files are limited to 2 MB and 5,000 rules.
- Sources and targets are limited to the database column size of 2,000 characters.
- Absolute targets may use `http://` or `https://` only. External destinations are
  allowed, so review an imported file before it can send visitors off-site.
- `source`/`source_path` and `target`/`target_url` are accepted as column and key names too.
- A source given as a full URL (`https://old.example.com/page`) is reduced to its path,
  except that a query-only WordPress permalink such as `/?p=123` keeps its query.
- A rule whose source path already exists is updated, not duplicated — uploading the same
  file twice leaves the same set of rules.
- Rows that cannot be used (missing column, unsupported status, a regex that does not
  compile) are skipped and reported instead of being stored as rules that never fire.
