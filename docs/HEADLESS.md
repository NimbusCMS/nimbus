# Using NimbusCMS headless

Nimbus renders a themed site out of the box, but every piece of content is also
available over a read API — so you can build a decoupled front end (Next, Astro,
a mobile app, anything that speaks HTTP) against the same content model. This is
the guide to reading Nimbus from the outside.

A **live, same-origin example** runs on the demo at
[`/example.html`](https://demo.nimbuscms.dev/example.html) — view source; it's a
single static file that does exactly what's below.

## Authenticate

The read API is behind a **bearer token**. Create one in the admin under
**API tokens**, giving it read scope — either a specific collection
(`posts:read`) or all content (`*:read`). A token with no scope is denied by
default (ADR 0006 lifecycle).

```
Authorization: Bearer <your-token>
```

Keep the token server-side for a real front end (a build step, an edge function,
your BFF). A token embedded in browser JavaScript is readable by anyone — only do
that for public, read-only content you're happy to expose (as the demo does).

## List a collection's entries

Only **live** entries (published, publish time reached) are returned — drafts and
scheduled entries are invisible here, exactly as on the rendered site.

```bash
curl -s https://your-site/api/v1/collections/posts/entries \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
  "data": [
    { "id": 12, "title": "Hello", "slug": "hello", "status": "published",
      "published_at": "2026-08-01 09:00:00", "fields": { "body": "…" } }
  ],
  "page": 1, "per_page": 20, "total": 1
}
```

`?page=N` paginates. Relation and media fields are expanded inline (and gated by
your token's scope — an out-of-scope relation is omitted, not leaked).

## Fetch one entry

```bash
curl -s https://your-site/api/v1/collections/posts/entries/hello \
  -H "Authorization: Bearer $TOKEN"
```

```json
{ "data": { "id": 12, "title": "Hello", "slug": "hello", "fields": { "body": "…" } } }
```

A draft, a scheduled-but-not-due, or an absent entry all return the same `404` —
nothing distinguishes "this exists but isn't public" from "no such entry".

## Preview a draft (ADR 0021)

To review an unpublished entry from a headless front end, use a **preview token**
— minted from the entry editor's **Preview draft** button (or its shareable link).
It is entry-scoped, short-lived, and served from a dedicated public endpoint that
needs **no API token**:

```bash
curl -s "https://your-site/api/v1/preview?token=<preview-token>"
```

```json
{ "data": { "id": 34, "title": "Work in progress", "slug": "wip", "fields": { … } } }
```

It returns exactly the one draft the token grants — it can't list, write, or read
any other entry, and it expires. Responses are `no-store` + `no-referrer`.

## A minimal browser client

```js
const API = 'https://your-site/api/v1';
const TOKEN = '…'; // read-only

async function get(path) {
  const res = await fetch(API + path, { headers: { Authorization: `Bearer ${TOKEN}` } });
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return (await res.json()).data;
}

const posts = await get('/collections/posts/entries'); // array
const one   = await get('/collections/posts/entries/hello'); // object

// A draft preview needs no API token — just the preview token:
const draft = await (await fetch(`${API}/preview?token=${previewToken}`)).json();
```

## Cross-origin (CORS)

Same-origin requests need nothing. To call the API from a **different** origin
(your front end on another domain), set `CORS_ALLOWED_ORIGINS` (comma-separated;
`*` allows any) so the API echoes the allow headers. It's off by default —
same-origin only — so a browser on an unlisted origin is refused.

## The full surface

`GET /api/v1/openapi.json` (behind the same bearer auth) returns an OpenAPI
document generated from *your* content model and **scoped to the presenting
token** — the definitive, always-current contract for your install. See also
[docs/COMPATIBILITY.md](COMPATIBILITY.md) for the API's versioning promise and
[docs/MCP.md](MCP.md) for driving the same content model over MCP.
