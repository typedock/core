# TypeDock API

TypeDock exposes a small external JSON API for integrations, deployment
scripts, content audits, and trusted automation. It is not used by the admin
UI, which stays session-based and CSRF-protected.

## Enable the API

Open **Settings -> API** in the admin panel and enable external API routes.
When disabled, `/api/*` returns `404`.

You can also force the API on with `API_ENABLED=true` in environment
configuration. When enabled this way, the admin switch is locked on.

## Authentication

All content and media endpoints require:

```http
Authorization: Bearer td_<prefix>_<secret>
```

Create keys from **Settings -> API**. The plaintext key is shown only once.
Keys can either inherit the creating user's role permissions or use fixed
scopes such as `posts:read`, `pages:read`, and `media:upload`.

## Response shape

List endpoints return:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 0,
    "page_count": 1
  }
}
```

Errors return:

```json
{
  "error": {
    "code": "not_found",
    "message": "Post not found."
  }
}
```

## Endpoints

| Method | Path | Scope |
|---|---|---|
| GET | `/api/v1` | none |
| GET | `/api/v1/manifest` | none |
| GET | `/api/v1/posts` | `posts:read` |
| POST | `/api/v1/posts` | `posts:create` |
| GET | `/api/v1/posts/{id}` | `posts:read` |
| PUT/PATCH | `/api/v1/posts/{id}` | `posts:edit_own` or `posts:edit_any` |
| DELETE | `/api/v1/posts/{id}` | `posts:delete_own` or `posts:delete_any` |
| GET | `/api/v1/pages` | `pages:read` |
| POST | `/api/v1/pages` | `pages:create` |
| GET | `/api/v1/pages/{id}` | `pages:read` |
| PUT/PATCH | `/api/v1/pages/{id}` | `pages:edit_own` or `pages:edit_any` |
| DELETE | `/api/v1/pages/{id}` | `pages:delete_any` |
| GET | `/api/v1/media` | `media:read` |
| POST | `/api/v1/media` | `media:upload` |
| GET | `/api/v1/media/{id}` | `media:read` |
| DELETE | `/api/v1/media/{id}` | `media:delete_own` or `media:manage_any` |

Publishing through `POST` or `PUT/PATCH` also requires `posts:publish` or
`pages:publish`.

## Query parameters

Content list endpoints accept:

| Parameter | Default | Notes |
|---|---:|---|
| `page` | `1` | 1-based page number |
| `per_page` | `20` | Maximum `100` |
| `status` | `published` | Use `all` to include every non-trash status; unpublished lists require `*:edit_any` |
| `locale` | current default | Filters by locale when provided |
| `search` | none | Searches title, excerpt, and body text |
| `order_by` | `updated_at` | `updated_at`, `published_at`, or `title` |

Media list endpoints accept `page`, `per_page`, `folder`, `mime_type`, and
`search`.

## Create or update content

Send a JSON object:

```json
{
  "title": "Hello API",
  "slug": "hello-api",
  "status": "draft",
  "excerpt": "A short summary.",
  "body": {
    "type": "doc",
    "content": []
  }
}
```

`body` is TypeDock's Tiptap JSON document. HTML is not accepted as a storage
format.

## Upload media

Use multipart form data:

```bash
curl -H "Authorization: Bearer $TYPEDOCK_API_KEY" \
  -F "file=@image.jpg" \
  https://example.com/api/v1/media
```
