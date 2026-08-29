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
| `Nimbus\Plugin\EventRegistrar` | subscribing to events, and **emitting** under the plugin's own namespace (`PluginContext::events()`, ADR 0014). `emit($name)` always prefixes the plugin id verbatim (`nimbuscms.inventory` + `low` → `nimbuscms.inventory.low`); an id rooted in a core event namespace is refused at load, so a plugin cannot forge a core event. Delivery is best-effort and depth-bounded — a throwing or looping subscriber cannot fail or hang the emitting operation |
| `Nimbus\Plugin\MigrationRegistrar` | declaring migrations for the plugin's own tables (ADR 0005). **Prefix your table names with your plugin slug** (`analytics_hits`, not `hits`) so two plugins can't collide; never create/alter core `nb_*` tables — a migration statement that does (DDL or DML against `nb_*`) is **rejected at registration** and the plugin is skipped (an *accident guard, like a linter — not a sandbox*: an installed plugin is trusted in-process code and can still reach `nb_*` from its runtime, per ADR 0001). **Each statement must be individually idempotent** (`… IF NOT EXISTS`): MySQL can't roll DDL back, a failed migration is isolated + retried, and your runtime must not assume a table/constraint exists until the migration is recorded |
| `Nimbus\Plugin\PluginStorage` | reading/writing the plugin's own tables (`PluginContext::storage()`, ADR 0005) |
| `Nimbus\Plugin\AdminPageRegistrar` | registering admin pages (`PluginContext::adminPages()`). A slug must be unique and not shadow a core section (both throw at registration → the plugin fails to load) |
| `Nimbus\Plugin\MaintenanceRegistrar` | registering maintenance/retention tasks (`PluginContext::maintenance()`), run by `nimbus prune` |
| `Nimbus\Plugin\SkillRegistrar` | publishing the plugin's **agent guide** (`PluginContext::skills()`, ADR 0013), served to agents as the MCP resource `nimbus://guide/plugin/{id}`. **Static markdown, bounded** (an over-long or empty fragment is rejected at registration → the plugin fails to load). It is **world-readable to any valid token** — put no secrets or per-tenant data in it — and is served as *reference documentation, not instructions*: never fed into the always-in-context brief, and wrapped in an untrusted-data envelope on read |
| `Nimbus\Plugin\CapabilitiesRegistrar` | declaring a grantable, wildcard-immune **management** capability (`PluginContext::capabilities()`, ADR 0015). `declare($label, $actions)` registers `{pluginId}:read`/`:write` — the resource is the plugin id, and the plugin id **must be namespaced** (contain a dot). It is exact-or-`admin` only, so the content `*:write` wildcard can never reach it (e.g. an Inventory `inventory:write`). A flat id, a duplicate, or a bad label/action fails the plugin's load |
| `Nimbus\Plugin\McpRegistrar` | registering the plugin's **MCP toolset** (`PluginContext::mcp()`, ADR 0016) — agent-facing tools. A plugin extends `Nimbus\Mcp\PluginToolset` and declares `PluginTool`s (`{name, action, schema, handler}`); the base gates **every** tool on the plugin's capability (a `write` tool needs `{pluginId}:write`) and namespaces every name (`{namespace}_{name}`), so an ungated or colliding tool cannot ship. The `namespace()` must be `[a-z][a-z0-9]*` and unique across plugins; a duplicate fails the plugin's load |
| `Nimbus\Mcp\PluginToolset` / `Nimbus\Mcp\PluginTool` | the base a plugin extends for MCP tools, and the per-tool value object (ADR 0016) |
| `Nimbus\Plugin\RouteRegistrar` | registering **public routes** (`PluginContext::routes()`, ADR 0017). `get`/`post`/`put`/`patch`/`delete($namespace, $path, $handler)` serve under `/ext/{namespace}/…` — a reserved prefix that cannot collide with content or shadow `/admin`/`/api`. Routes are **public** (outside admin auth, no automatic CSRF): the plugin owns its auth — verify a webhook signature over `Request::rawBody()`, or check a token. A namespace is unique across plugins (a clash fails the load) |
| `Nimbus\Site\HeadContributor` | the head-contribution contract (ADR 0004) |
| `Nimbus\Site\PageContext` | the page data a head contributor receives |
| `Nimbus\Support\CoreEvents` | event-name constants a plugin may listen for |
| `Nimbus\Content\FieldType` | the field-type contract. **`renderInput`/`renderCell` MUST escape the untrusted `$value` (`View::e()`)** — it comes from authors/write-scoped tokens and is embedded raw in the admin |
| `Nimbus\Content\FieldTypes\BaseType` | the base class field types extend |
| `Nimbus\Content\Field` | the field value object passed to a field type |
| `Nimbus\Content\UnknownFieldType`, `DuplicateFieldType` | exceptions a plugin may catch |

Explicitly **internal**, whatever their visibility: `Application`, controllers,
repositories, `Connection`, `EntryService`, `CollectionService`, `Router`, `Auth`,
`EventDispatcher`, `PluginLoader`. A plugin depending on any of them will break,
and that is not a bug in Nimbus.

`Request` and `Response` are internal **except** for a small read/return surface a
plugin legitimately touches — an admin-page handler receives a `Request` and
returns a `Response`, and a `request.handled` listener is handed both. Stable for
plugins: `Request::$method`, `$path`, `query()`, `input()`, `header()`; returning
`Response::html()`/`redirect()`/`json()`/`download()` and reading `Response::$status`,
`$body`, `header()`. Everything else on both classes (construction from globals,
`ip()`, `bearerToken()`, raw-body `json()`, `all()`, `file()`, `send()`, header
mutation) is internal and may change.

> **Never log or persist the raw `Request`** (or its Authorization header / login
> POST body) from a `request.handled` listener — it carries live credentials. The
> event payload hands you the object today for convenience; its shape may become a
> data-only value object before `1.0`, so read what you need at handling time and
> keep only non-secret facts.

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
  validation. The top-level `code` is a stable machine-readable slug — branch on
  it, not the `message`: `unauthorized` (401), `forbidden` (403), `not_found`
  (404), `invalid` (422), `missing_provider` (422), `precondition_required`
  (428), `precondition_failed` (412), `rate_limited` (429).
- **Structured validation errors** — a `422` carries `fields`, a map of the
  submitted input name (a collection field handle, or `title`/`slug`/`published_at`) to a
  `{ code, message }` object, so a client — human or agent — branches on the
  per-field `code` and shows the `message`. Per-field codes: `required`,
  `invalid` (a type/format/choice failure). A `missing_provider` failure (a field
  type whose plugin is unavailable) is **top-level** (`error.code`), not a field
  entry, and its `fields` map is empty. The code vocabulary is **additive-only**:
  new codes may appear over time (a general `invalid` may become more specific),
  existing codes are never repurposed, and **a client must treat an unknown code
  as `invalid`**. The MCP surface returns the same `{ code, message }` per-field
  shape.
- **Auth** — a bearer token (`Authorization: Bearer …`), with per-collection
  `read`/`write` scopes: an out-of-scope collection answers `403` `forbidden`,
  and cannot be told apart from one that does not exist. A token's authority is
  its explicit scopes, optionally widened by a **bound role** whose capabilities
  apply *live* ([ADR 0011](adr/0011-roles.md)) — tightening or deleting the role
  reaches the token at its next request. **Behaviour change (0.x, pre-1.0):** a
  token with *no* scopes now denies by default; the legacy "empty abilities →
  read-all" grant from ADR 0006 was removed. Any token that relied on it must be
  granted explicit scopes or bound to a role.
- **Writes** map the JSON body (`{ title, slug?, status?, fields }`) to the same
  service the admin uses — only a collection's declared fields are bound. A write
  needs the collection's `write` scope; a `PATCH`/`DELETE` needs `If-Match`
  carrying the entry's current `ETag` (a read returns it) — absent is `428`,
  stale is `412`, so machine clients cannot silently overwrite each other. The
  check is an atomic compare-and-swap at the write, not just at request entry, so
  a write that races another between the read and the write also gets `412` (no
  lost update). `If-Match: *` is honored as "the version I just read" — a `*` write
  that loses that race is a `412` too; retry with a fresh read.
  Values are **bounded** (a violation is a `422`, never a `500`): `title` ≤ 255,
  an explicit `slug` ≤ 191, a scalar text field to its `maxlength` field option
  (default 255 text / 50 000 textarea, and every scalar string to a hard ceiling),
  a relation field to 100 targets, and `published_at` must parse. The request
  body itself is bounded by your PHP/MySQL deployment config (`post_max_size`,
  `max_allowed_packet`), not the app.
- **OpenAPI** — `GET /api/v1/openapi.json` returns an OpenAPI 3.0 document
  generated from the live content model (behind the same bearer auth), **scoped to
  the presenting token**: it describes only the collections that token can read
  (write operations only where it can write), so the spec can't enumerate what the
  endpoints hide. For the **full** document (all collections), use `nimbus openapi`
  — the CLI is a trusted local operator — or present an `admin`/`*:read` token. See
  [ADR 0008](adr/0008-openapi.md).
- **MCP** — `POST /api/v1/mcp` (and stdio `nimbus mcp`) exposes the CMS to agents
  over JSON-RPC 2.0, gated by the same scoped tokens. The **tool set is generated
  from the live model and the token's scopes**, so — like the content shape
  itself — it is not frozen: a `0.x` release may add, rename or remove tools.
  Treat tool names/inputs as evolving until `1.0`. See [docs/MCP.md](MCP.md) and
  [ADR 0009](adr/0009-mcp-control-surface.md). Likewise the **agent-guidance
  resources** (`initialize.instructions`, the `nimbus://guide/*` resource URIs and
  their content — [ADR 0013](adr/0013-mcp-agent-guidance.md)) are documentation,
  evolving until `1.0`; do not parse them as a stable contract.
- **Rate limiting** — requests are limited per token (and per IP for the
  unauthenticated flood guard); over the limit is `429` `rate_limited` with a
  `Retry-After` header. Limits are deployment config, not part of the contract.
- **CORS** — off by default (same-origin). When origins are allow-listed, an
  allowed `Origin` gets `Access-Control-Allow-Origin` (echoed, with
  `Vary: Origin`); browser `OPTIONS` preflights are answered without a token and
  advertise the methods the API serves — `GET, POST, PATCH, DELETE, OPTIONS` — and
  the headers `Authorization, Content-Type, If-Match`, so an allow-listed browser
  app can read, write and call MCP cross-origin. Auth is bearer-only: no cookies,
  no `Access-Control-Allow-Credentials`. Preflights are counted by the per-IP flood
  guard, and the API surface (`/api/**`) never sets a session cookie.
- **Methods** (kernel-wide, not just the API) — a `HEAD` is served by the `GET`
  route: same status and headers, no body. A request whose path matches a route
  but not its method gets `405` with an `Allow` header (rather than `404`); a path
  matching no route is still `404`. Note the site's `/{collection}` catch-alls
  mean a wrong-method request to almost any path is a `405`, not a `404`.
- **Visibility** — only the *live* set is served (published, `published_at` in
  the past); drafts and scheduled entries are indistinguishable from absent.
- **Field values** pass through each field type's `toApi()` — e.g. a `boolean`
  (toggle) field is a JSON `true`/`false`, not the `1`/`0` it is stored as.
- **Reference fields are expanded** so a client needs no second request. A
  `media` field is the media object (`{ id, url, alt, mime, width, height }`) or
  `null`. A `relation` field is a JSON array of `{ id, slug, title }` objects, in
  link order — and only the *live* targets: a relation to a draft, a not-yet-due
  scheduled entry, or an archived one contributes nothing, so a relation never
  reveals an unpublished entry. A relation only ever yields entries **in its
  declared target collection**: an id outside that collection (or nonexistent) is
  dropped on write and never expands — uniformly with an absent id, never a 500.

`v1` is the stability boundary. Additive changes (new optional query params, new
fields in a response object) are minor. A breaking change to `v1`'s shapes ships
a `v2` route rather than mutating `v1`. Internal serializer refactors that do not
change the wire shape are not breaking.

Not yet part of the contract, and may appear without a version bump until they
do: filtering/sorting params, sparse fieldsets, ETags.

## Roles & capabilities

Authorization for users and tokens is one `resource:action` capability vocabulary
([ADR 0011](adr/0011-roles.md), [docs/ROLES.md](ROLES.md)). What is promised — the
**model and its guarantees**, not the exact strings:

- **Deny-by-default.** No capability, no access — for people and machines alike.
- **The management boundary.** `schema`, `media`, `users`, `tokens`, `settings`,
  `roles` are grantable only exactly (or by `admin`); the content wildcard
  `*:action` **never** confers a management capability.
- **Reserved handles (FU-4/FU-6).** A **collection** handle may not be a
  management-capability name (`schema`/`media`/`users`/`tokens`/`settings`/
  `roles`/`admin`) or a built-in route prefix (`api`/`uploads`/`theme`) — such a
  handle would be judged under management authz rules or shadowed by a core
  route. A **field** handle may not be a built-in entry attribute (`title`/
  `slug`/`published_at`). Both are rejected at schema-create on the admin form
  and over MCP with a friendly error. The reservation is **create-time only**: a
  collection or field that already carries such a name (from before this guard)
  still edits and saves — its handle is never renamed out from under it.
- **Content implication.** `handle:write` implies `handle:read`; management caps
  carry no such implication (each is explicit).
- **Subset-only granting.** No surface (admin UI, MCP) lets an actor grant, into a
  role or a token, a capability it does not itself hold. (The CLI is a trusted
  local operator and is exempt by design.)
- **Roles are admin-composed**; the three **system roles** (`admin`/`editor`/
  `author`) are seeded and **undeletable**.

Like the MCP tool set, the specific capability **names** and the role **schema**
are **evolving until `1.0`** — treat them as unfrozen. One behaviour change
already shipped in `0.x`: a token with no scopes now denies (the legacy
empty→read-all grant was removed).

## The theme contract

A theme is a directory under `themes/{name}/` — a `theme.json` and plain-PHP
templates in `templates/`, rendered by `View`. A template is handed a data-only
view-model (the same shape the API serializes) and two helpers, and nothing
else — no services, no repositories, no database:

- `$e($value)` — escape a value for output.
- `$partial($name, $data = [])` — include another template from the same theme
  (a shared `header`, `footer`, `nav`), returning rendered HTML.
- `$cspNonce` — the per-request CSP nonce. **Both `script-src` and `style-src`
  are nonce-only** (no `'unsafe-inline'`): an inline `<script>` *or* `<style>`
  must carry `nonce="<?= $e($cspNonce) ?>"` or it will not run. **Inline `style=`
  attributes cannot be nonce'd and are blocked** — use a class or a CSS custom
  property instead. External files under `assets/` are also fine.
  - *Page cache:* inline nonce'd `<script>`/`<style>` work on cached pages too.
    When `PAGE_CACHE_TTL > 0`, the nonce is stored with the page and re-emitted on
    every hit, so the header and the cached body always agree. The nonce is then
    **stable for that cache entry's life** (a cached public page is identical for
    all viewers, and is re-rendered with a fresh nonce on any content write or at
    TTL expiry) — safe, and it is the only per-request value the cache manages, so
    a theme must not bake other per-visitor state (a CSRF token, an A/B bucket)
    into a cacheable public page.
  - *External analytics beacons:* a nonce'd external `<script src>` **loads**, but
    the site CSP has no `connect-src`, so a hosted analytics script's `fetch`/
    `sendBeacon` to a third-party endpoint is still blocked (the script runs, the
    event doesn't send). Self-hosted or reverse-proxied analytics (served from
    your own origin, i.e. `'self'`) works fully today.
  - *Note — user content:* inline `style=` attributes inside rendered entry
    bodies (e.g. Markdown/raw HTML) do not apply on public pages. This is
    deliberate (it also makes CSS injection via content inert); style content with
    classes/theme CSS, not inline attributes.

The active theme is named in `config/theme.php`. Templates rendered today:
`layout` (the shell), `collection`, `entry`, and an optional `404`. A theme may
**specialise** a template for one collection — `entry-{handle}` or
`collection-{handle}` (e.g. `entry-homepage`) — and Nimbus falls back to the
generic `entry`/`collection` when the specific one is absent.

**Pagination.** A collection index carries `$page` and `$total_pages`. A `?page`
beyond the last page is a **404** (page 1 always renders — an empty collection is a
valid "no entries" view); this keeps an out-of-range page from being a cacheable
empty `200`. (Behavior change in `0.x`: such a page used to render a `200` empty
list.)

**Collection navigation (`$nav`).** For building a sidebar (a docs tree, a
knowledge base), `collection` and `entry` templates receive `$nav` — the
collection's **live** entries in the same shape as a `$entries` item
(`{ slug, title, published_at, fields }`, with public field values), for the theme
to group and sort (e.g. by a `section`/`order` field). It is **opt-in**: a theme
declares which collections get it in its `theme.json` `nav` key — a list of
handles, or `true` for all. Without the opt-in (or on an un-browsable collection —
a singleton or the blocks store) `$nav` is an empty list, always defined so a
template never needs to guard it. It is **bounded** to the first 200 live entries
(index order); a collection larger than that is a feed, not curated navigation.
Draft and scheduled entries never appear. Cross-reference the cache note below:
publishing an entry flushes the whole page cache, so every page's `$nav` refreshes
together.

**Page metadata.** The view-model carries `$meta` for the document head —
`{ title, description, canonical, og_type }`. The starter renders a
`<meta name="description">`, a `<link rel="canonical">`, and Open Graph tags from
it. A description comes from an entry's `excerpt`/`summary`/`description` field,
then the collection's description, then the site default — the `site.description`
setting when set, otherwise `config/site.php`'s `description` (the file is the
default the settings store overrides). The site home (`/`) resolves the same way:
the `site.home` setting when set, else `config/site.php`'s `home`.

**Site settings.** Admin-editable site configuration lives in a typed store
(`nb_settings`): today `site.title`, `site.home` and `site.description`. Only
registered keys are readable-with-a-default or writable — it is an allow-list, not
free key/value. The file layer provides the default each setting overrides, so a
fresh install works from the files with no seed step: `site.home`/`site.description`
default from `config/site.php`, and `site.title` from `.env`'s `APP_NAME` (the
established app-identity var) — the store overrides whichever the file layer sets.
The site title is the brand shown in the admin, the public theme, Open Graph tags
and the OpenAPI `info.title`. Deploy/environment configuration (DB, URL, proxies,
upload/rate limits, enabled plugins, active theme) is **not** in this store — it
stays in `.env` + `config/*.php`, and `Support\Config` stays DB-free. Writes are
gated on `settings:write`; reads are public (title/home/description render on the
public site).

**Reusable blocks.** A collection with the handle `blocks` holds shared content
fragments — the live entries are passed to the view-model as `$blocks`, keyed by
slug. A theme renders one by slug (the starter renders an `announcement` block as
a site-wide bar). Defined once, rendered anywhere; only live blocks appear.

The `blocks` collection and any **single-kind** collection are **not publicly
routable** (SVM-4): `/blocks`, `/blocks/{slug}`, and `/{single-handle}` (plus its
one entry) return `404` — a fragment is embedded, not a standalone page, and a
single's one entry is the site home at `/`. They are also omitted from the
sitemap (the two rules share one predicate, so they cannot disagree). Headless
access is unaffected — the token-scoped `/api/v1/collections/blocks/entries` still
serves them. An operator with genuine inbound links to a former URL can add an
exact-path redirect in `config/redirects.php`.

**`robots.txt`, `sitemap.xml`, `llms.txt`.** Core serves all three from the site
root. `sitemap.xml` and `llms.txt` list only publicly browsable collections (the
same SVM-4 gate). `llms.txt` (llmstxt.org) is the agent-facing sibling: a
plain-text file naming the site, its public pages, and — the one thing an agent
can't infer from the HTML — that the site is operable over MCP at `/api/v1/mcp`
(token-gated), with the built-in guide reached via the `nimbus://guide/core` MCP
resource. It carries no version string, and editor-set values (site title,
description, collection names) are flattened to a single line so they can't forge
a section.

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

**Cache-key contract (HTTP-6).** A cached page's output must be a pure function
of its **path and the `page` query parameter** — those are the only inputs the
cache key varies on. This is deliberate: the key is *not* varied on the rest of
the query string, so that an anonymous visitor cannot mint an unbounded number
of cache files with `?anything=1,2,3,…` (the same disk-fill bound that caps
`?page`). The core front end honors this — it reads no query input but `page`.
If your theme or a plugin renders a **query-varying public page** (a `?tag=`,
`?q=`, `?sort=`, or search result whose body changes with the query), that page
must **not** be served from the cache, or a first visitor's result will be
replayed for every later query on that path. There is no per-page opt-out today;
run such a site with `PAGE_CACHE_TTL=0` (a store-side `no-store` opt-out will be
added when a query-varying core feature needs it). `/foo` and `/foo/` are one
route but two cache keys — a bounded (×2) duplication, not a correctness issue.

## SSO providers

SSO ("Sign in with Google / GitHub", [ADR 0012](adr/0012-oauth-sso.md)) is a
**core** subsystem, optional and **off by default** — it is not a plugin
capability, and there is no way to register a provider from a plugin yet. The
built-in providers are Google and GitHub, enabled per-provider by setting
`OAUTH_<PROVIDER>_CLIENT_ID` and `OAUTH_<PROVIDER>_CLIENT_SECRET`.

The `Nimbus\Auth\OAuth\OAuthProvider` interface (and the `OAuthIdentity` it
returns) is the seam the two built-in adapters implement. It is **internal and
not frozen** — a `0.x` release may change its methods. Do not depend on it from
outside core.

The callback (redirect) URL you register with each provider is derived from
`APP_URL`, not the request Host header: it is always
`<APP_URL>/admin/oauth/<provider>/callback`. If `APP_URL` is wrong, the flow
fails. This path is stable within `0.x`.

## Deployment behind a proxy

Every absolute URL Nimbus generates — password-reset and invitation email links,
the OAuth redirect URL, canonical and sitemap URLs — is built from `APP_URL`,
**never** from the request `Host` / `X-Forwarded-Host` (which a client can forge
even through a correctly configured proxy, and would otherwise let an attacker
point a reset link at their own domain). Behind a TLS-terminating proxy you must
therefore set `APP_URL` to your public `https://` origin.

Forwarded headers (`X-Forwarded-For` / `X-Forwarded-Proto`) are honored only from
peers listed in `TRUSTED_PROXIES`, and only to determine the client IP
(throttling) and the request scheme (the session cookie's `secure` flag). There
is intentionally no forwarded-*host* accessor. When a request arrives through a
trusted proxy but `APP_URL` still looks like localhost (or `http://` while the
request is HTTPS), the admin **Plugins** page shows a misconfiguration warning.

**Serving uploaded media.** Uploads live under the public docroot
(`public/uploads/`) and are served by your front webserver, so Nimbus's response
headers (`nosniff`, CSP) never reach them. The upload allow-list (no HTML/SVG,
random names, MIME-sniffed) is the primary control; as defence-in-depth, set
`X-Content-Type-Options: nosniff` (and a `Content-Disposition: inline`) on
`/uploads/*` at the webserver:

```nginx
# nginx
location ^~ /uploads/ { add_header X-Content-Type-Options "nosniff" always; }
```
```apache
# Apache
<LocationMatch "^/uploads/">
    Header always set X-Content-Type-Options "nosniff"
</LocationMatch>
```
```caddy
# Caddy
@uploads path /uploads/*
header @uploads X-Content-Type-Options "nosniff"
```

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
- **Event payload shapes.** Event subscription and namespaced emission **are** a
  plugin capability (`EventRegistrar`, `PluginContext::events()`, ADR 0014), and
  the `CoreEvents` names are stable — but the **payload arrays** each event
  carries are not frozen yet, and a plugin's *own* emitted event names/payloads
  are that plugin's contract to keep, not core's.
- **Anything reached by reflection.** Making a private thing accessible does
  not make it supported.
