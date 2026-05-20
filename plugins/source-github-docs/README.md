# GitHub Docs Source Adapter

GitHub Docs Source Adapter adds a `GitHub Markdown Docs` External Source
adapter. It reads Markdown files from a GitHub repository directory, normalizes
each file into TypeDock's External Source shape, and relies on the core
`[resource.content|markdown]` formatter for safe Markdown-to-HTML rendering.

Suggested setup for typedock.com:

- Adapter: `GitHub Markdown Docs`
- URL prefix: `docs`
- Owner: `typedock`
- Repository: `core`
- Branch: `main`
- Docs path: `docs`
- Detail template:

```text
[resource.content|markdown]

Source: [resource.raw.fields.html_url|url]
```
