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
  validation. The top-level `code` is a stable machine-readable slug — branch on
  it, not the `message`: `unauthorized` (401), `forbidden` (403), `not_found`
  (404), `invalid` (422), `missing_provider` (422), `precondition_required`
  (428), `precondition_failed` (412), `rate_limited` (429).
- **Structured validation errors** — a `422` carries `fields`, a map of the
  submitted input name (a collection field handle, or `title`/`slug`) to a
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
  stale is `412`, so machine clients cannot silently overwrite each other.
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
  [ADR 0009](adr/0009-mcp-control-surface.md).
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
  property instead. Prefer external files under `assets/`.
  - *Caveat — page cache:* the nonce is minted fresh per request while
    `PageCache` stores HTML only, so a cached page's embedded nonce won't match
    the next response's header. If you run with `PAGE_CACHE_TTL > 0`, a cached
    public page **must** use external CSS/JS under `assets/`, not inline
    nonce'd `<style>`/`<script>`.
  - *Note — user content:* after this change, inline `style=` attributes inside
    rendered entry bodies (e.g. Markdown/raw HTML) no longer apply on public
    pages. This is deliberate (it also makes CSS injection via content inert);
    style content with classes/theme CSS, not inline attributes.

The active theme is named in `config/theme.php`. Templates rendered today:
`layout` (the shell), `collection`, `entry`, and an optional `404`. A theme may
**specialise** a template for one collection — `entry-{handle}` or
`collection-{handle}` (e.g. `entry-homepage`) — and Nimbus falls back to the
generic `entry`/`collection` when the specific one is absent.

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
