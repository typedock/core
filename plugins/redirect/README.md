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
