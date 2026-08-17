# Compatibility and deprecation policy

What a plugin author can rely on, and what can change without warning.

> **Nimbus is pre-1.0.** Until `1.0.0`, the guarantees below are intentions
> rather than promises: a `0.x` minor release may break the plugin API if a
> design turns out to be wrong. Better to break it at `0.3` than to carry a
> mistake to `1.0` and support it forever.

## The public plugin API

Only these are public. Everything else in `Nimbus\` is internal and may change
in any release, including patch releases.

| Namespace / class | What it covers |
|---|---|
| `Nimbus\Plugin\Plugin` | the interface a plugin implements |
| `Nimbus\Plugin\PluginContext` | what a plugin is handed |
| `Nimbus\Plugin\FieldTypeRegistrar` | registering field types |
| `Nimbus\Plugin\HeadRegistrar` | registering document-head contributors |
| `Nimbus\Plugin\EventRegistrar` | subscribing to events (`PluginContext::events()`) |
| `Nimbus\Plugin\MigrationRegistrar` | declaring migrations for the plugin's own tables (ADR 0005) |
| `Nimbus\Plugin\PluginStorage` | reading/writing the plugin's own tables (`PluginContext::storage()`, ADR 0005) |
| `Nimbus\Plugin\AdminPageRegistrar` | registering admin pages (`PluginContext::adminPages()`) |
| `Nimbus\Site\HeadContributor` | the head-contribution contract (ADR 0004) |
| `Nimbus\Site\PageContext` | the page data a head contributor receives |
| `Nimbus\Support\CoreEvents` | event-name constants a plugin may listen for |
| `Nimbus\Content\FieldType` | the field-type contract |
| `Nimbus\Content\FieldTypes\BaseType` | the base class field types extend |
| `Nimbus\Content\Field` | the field value object passed to a field type |
| `Nimbus\Content\UnknownFieldType`, `DuplicateFieldType` | exceptions a plugin may catch |

Explicitly **internal**, whatever their visibility: `Application`, controllers,
repositories, `Connection`, `EntryService`, `CollectionService`, `Router`,
`Request`, `Response`, `Auth`, `EventDispatcher`, `PluginLoader`. A plugin
depending on any of them will break, and that is not a bug in Nimbus.

`PluginContext` grows one capability at a time, each alongside a plugin that
needs it. New capabilities are additive and never break existing plugins.

## The public HTTP API

Separate from the plugin PHP surface, the API under `/api/v1` is a public
**wire** contract — an application consuming it depends on the request and
response shapes, not on any PHP class. What is promised:

- **Routes** — read: `GET …/collections/{handle}/entries` (paginated) and
  `GET …/collections/{handle}/entries/{slug}`; write ([ADR 0007](adr/0007-write-api.md)):
  `POST …/entries` (create → `201` + `Location`), `PATCH …/entries/{slug}`
  (update → `200`), `DELETE …/entries/{slug}` (→ `204`).
- **Envelope** — success is `{ "data": …, "meta": { page, per_page, total,
  total_pages } }` (meta on collections); error is
  `{ "error": { "status", "code", "message" } }`, plus a `fields` map on
  validation. The `code` is a stable machine-readable slug — branch on it, not
  the `message`: `unauthorized` (401), `forbidden` (403), `not_found` (404),
  `invalid` (422), `precondition_required` (428), `precondition_failed` (412),
  `rate_limited` (429).
- **Auth** — a bearer token (`Authorization: Bearer …`), with per-collection
  `read`/`write` scopes: an out-of-scope collection answers `403` `forbidden`,
  and cannot be told apart from one that does not exist.
- **Writes** map the JSON body (`{ title, slug?, status?, fields }`) to the same
  service the admin uses — only a collection's declared fields are bound. A write
  needs the collection's `write` scope; a `PATCH`/`DELETE` needs `If-Match`
  carrying the entry's current `ETag` (a read returns it) — absent is `428`,
  stale is `412`, so machine clients cannot silently overwrite each other.
- **OpenAPI** — `GET /api/v1/openapi.json` returns an OpenAPI 3.0 document
  generated from the live content model (behind the same bearer auth); `nimbus
  openapi` prints the same for build pipelines. See [ADR 0008](adr/0008-openapi.md).
- **Rate limiting** — requests are limited per token (and per IP for the
  unauthenticated flood guard); over the limit is `429` `rate_limited` with a
  `Retry-After` header. Limits are deployment config, not part of the contract.
- **CORS** — off by default (same-origin). When origins are allow-listed, an
  allowed `Origin` gets `Access-Control-Allow-Origin` (echoed, with
  `Vary: Origin`); browser `OPTIONS` preflights are answered without a token.
- **Visibility** — only the *live* set is served (published, `published_at` in
  the past); drafts and scheduled entries are indistinguishable from absent.
- **Field values** pass through each field type's `toApi()` — e.g. a `boolean`
  (toggle) field is a JSON `true`/`false`, not the `1`/`0` it is stored as.
- **Reference fields are expanded** so a client needs no second request. A
  `media` field is the media object (`{ id, url, alt, mime, width, height }`) or
  `null`. A `relation` field is a JSON array of `{ id, slug, title }` objects, in
  link order — and only the *live* targets: a relation to a draft, a not-yet-due
  scheduled entry, or an archived one contributes nothing, so a relation never
  reveals an unpublished entry. A dangling reference reads as absent, never a 500.

`v1` is the stability boundary. Additive changes (new optional query params, new
fields in a response object) are minor. A breaking change to `v1`'s shapes ships
a `v2` route rather than mutating `v1`. Internal serializer refactors that do not
change the wire shape are not breaking.

Not yet part of the contract, and may appear without a version bump until they
do: filtering/sorting params, sparse fieldsets, ETags.

## The theme contract

A theme is a directory under `themes/{name}/` — a `theme.json` and plain-PHP
templates in `templates/`, rendered by `View`. A template is handed a data-only
view-model (the same shape the API serializes) and two helpers, and nothing
else — no services, no repositories, no database:

- `$e($value)` — escape a value for output.
- `$partial($name, $data = [])` — include another template from the same theme
  (a shared `header`, `footer`, `nav`), returning rendered HTML.

The active theme is named in `config/theme.php`. Templates rendered today:
`layout` (the shell), `collection`, `entry`, and an optional `404`. A theme may
**specialise** a template for one collection — `entry-{handle}` or
`collection-{handle}` (e.g. `entry-homepage`) — and Nimbus falls back to the
generic `entry`/`collection` when the specific one is absent.

**Page metadata.** The view-model carries `$meta` for the document head —
`{ title, description, canonical, og_type }`. The starter renders a
`<meta name="description">`, a `<link rel="canonical">`, and Open Graph tags from
it. A description comes from an entry's `excerpt`/`summary`/`description` field,
then the collection's description, then `config/site.php`'s `description`.

**Reusable blocks.** A collection with the handle `blocks` holds shared content
fragments — the live entries are passed to the view-model as `$blocks`, keyed by
slug. A theme renders one by slug (the starter renders an `announcement` block as
a site-wide bar). Defined once, rendered anywhere; only live blocks appear.

**Navigation menus.** `config/menus.php` defines named menus, each a list of
`{label, url}` items; the view-model carries them as `$menus`, and the starter
header renders `$menus['main']`. Malformed entries are dropped before a template
sees them. Editor-managed menus (an admin builder) are a later capability.

**Static assets.** Files under a theme's `assets/` directory are served at
`/theme/assets/<path>` (e.g. `assets/app.css` → `/theme/assets/app.css`), so a
theme can ship real stylesheets, scripts, images and fonts instead of inlining
them. Only an allowlist of extensions is served (CSS, JS, common image and font
types) — a theme's PHP is never disclosed — and the path cannot escape `assets/`.
Bodies are served through PHP, which suits the modest files a theme ships; a
front webserver may serve `themes/*/assets` directly in production if desired.

This contract is **not frozen**. Template names, the view-model keys, the helper
set, and the rendered pages may still change before `1.0.0`. Copy
`themes/starter/` as a starting point, and expect to adjust it across `0.x`
releases.

## Page caching

Off by default. Set `PAGE_CACHE_TTL` to a positive number of seconds to cache
rendered public pages (never `/admin`, `/api`, or theme assets). The cache is
flushed on every content write, and the TTL bounds staleness for time-based
changes such as a scheduled entry becoming live. The on-disk cache format under
`storage/` is internal and may change without notice.

## Versioning

[Semantic Versioning](https://semver.org). Against the **public plugin API**
above, not the whole codebase.

| Change | 0.x | 1.x+ |
|---|---|---|
| New capability on `PluginContext` | minor | minor |
| New optional field-type option | minor | minor |
| Breaking change to the public API | **minor** | major |
| Internal refactor (controllers, services, HTTP) | patch/minor | patch/minor |
| Bug fix | patch | patch |

Plugins declare their range in `composer.json`:

```json
"require": { "nimbuscms/nimbus": "^0.1" }
```

`nimbuscms/nimbus` is a `project` package that plugins depend on today. Whether
the public contracts eventually split into a separate `nimbuscms/core` package
is deferred until installing Nimbus as a dependency is a real requirement —
splitting early buys synchronisation overhead before the API has been proven.

## Deprecation

From `1.0.0`, anything public that is going away:

1. keeps working for the whole of the current major version;
2. is marked `@deprecated` in the docblock, naming the replacement;
3. triggers `E_USER_DEPRECATED` where a runtime warning is useful;
4. is listed in `CHANGELOG.md` under **Deprecated** with a migration note;
5. is removed no earlier than the next major.

Before `1.0.0`, removals may happen in a minor release, but always with a
`CHANGELOG.md` entry explaining what to do instead.

## Supported versions

| | |
|---|---|
| PHP | 8.2 and 8.3. A new PHP requirement is a minor bump pre-1.0, a major after. |
| MySQL | 8.0+ |
| Security fixes | latest minor only, until there is a release cadence worth committing to |

Plugins should test against the **lowest and current** core versions they claim
to support. `plugin-markdown` runs its matrix on PHP 8.2 and 8.3 for the same
reason.

## What is not covered

- **Database schema.** Table and column names are internal. Read content
  through services, never `nb_*` tables directly.
- **Admin HTML and CSS.** Class names and markup change freely.
- **Event payload shapes.** `CoreEvents` names are stable; payload arrays are
  not frozen yet, and events are not a plugin capability at all yet.
- **Anything reached by reflection.** Making a private thing accessible does
  not make it supported.
