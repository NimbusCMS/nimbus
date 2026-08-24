# Nimbus threat catalog

The recurring loophole classes that fell PHP CMSes, each tied to the **actual
Nimbus surface** it applies to and the **smallest control** that closes it. This
is the Attacker's and Defender's starting checklist — not an abstract OWASP dump.
Update it via the self-learning loop: when a finding class recurs, promote it
here with the file it bit.

Paths are under `src/` unless noted. "Check" is what to verify in the diff.

---

## 1. Broken object-level authorization (IDOR) — *the top CMS killer*

The route is guarded but the **object** is not: an authenticated actor reaches an
object they shouldn't by changing an id/slug. Becomes acute the moment API token
**scopes** land — a token scoped to one collection must not read/write another's
entries or media.

- **Surfaces:** `Content/Permissions.php`, `Admin/EntriesController.php`,
  `Admin/MediaController.php`, `Api/ApiController.php`,
  `Http/Middleware/ApiAuthMiddleware.php`, `Content/EntryRepository.php`,
  `Media/MediaRepository.php`.
- **Check:** for every read/write, is the check *"this actor may act on **this
  object**"* — not merely *"this actor is logged in / holds a token"*? Is the
  token's scope enforced **in the query/service**, not just validated at the
  middleware door and then forgotten? Ownership (`update_own` vs `update_any`)
  actually consulted?
- **Control:** enforce authorization at the service/query layer against the
  concrete object + actor + scope; never trust a route guard alone. A scope is a
  **filter on data**, not a boolean at the entrance.

## 2. Privilege escalation / scope confusion

A low-privilege user or narrow token gains more than granted: a read token that
can write, a scope that widens through a relation, a role bundle that leaks a
capability. Directly relevant to the incoming `ApiToken.abilities` work
(currently stubbed — "every token can read today").

- **Surfaces:** `Api/ApiToken.php`, `Api/ApiTokenRepository.php`,
  `Content/Permissions.php`, `Auth/User.php`.
- **Check:** effective permission = **token scope ∩ user/role authorization** —
  the *intersection*, never the union or the more-permissive of the two. New
  scopes fail **closed** (unknown/absent scope → deny). Relations/expansions
  don't leak data the scope excludes.
- **Control:** deny-by-default; explicit intersection; an authorization matrix
  test (actor × scope × object × action) that forces every new scope to declare
  its answers.

- **Standing check — escalation at MINT (promoted after two hits: Slice 4 MCP
  `mint_token`, Slice 4b `/admin/tokens`).** Any surface that mints/grants a
  token or assigns a role must apply **subset-only over the *entire* granted set**
  — every explicit scope **and** every capability of any bound role — checked
  against what the *minter* holds (`Gate::holds` / `TokenPrincipal::can`), and
  reject on the first ungrantable one, **before** the write. Two traps this class
  keeps springing: (a) guarding the *new* field (the role) while leaving an
  *existing* field (the scopes) unchecked — check the union, not the delta; (b)
  mistaking a surface's *limitation* for a *control* ("the form only offers read")
  — a limitation is not an authz boundary. A client-side filtered dropdown is
  never the gate; the server re-resolves and re-checks. Reuse one helper
  (`firstUnheld`/`firstUngrantable`), never a per-surface reimplementation.

## 3. SQL injection

- **Surfaces:** `Database/Connection.php` (PDO facade), every `*Repository.php`,
  `Plugin/PluginStorage.php` (plugin-owned queries), `Support/PageCache.php` keys.
- **Check:** all values **parameter-bound** (`:name`), never interpolated. The
  parts PDO *can't* bind — table/column identifiers, `ORDER BY`, `LIMIT`,
  direction — are **allow-listed against a fixed set**, never taken raw from
  input. Watch dynamic sort/filter params, the API's future filtering, and plugin
  storage queries built from plugin input.
- **Control:** bound parameters everywhere; identifier/keyword allow-lists for the
  unbindable parts.

## 4. Cross-site scripting (XSS)

- **Surfaces:** `View/View.php` and theme templates (`View/themes/**`,
  `themes/**`), `Site/*`, any admin field rendering rich/user text, plugin
  head contributions (`Site/HeadContributor*`), `Content/FieldTypes/*` output.
- **Check:** output **escaped by default** by the view layer, not by the author
  remembering `htmlspecialchars`. Context-correct escaping (HTML vs attribute vs
  JS vs URL). Rich-text/HTML fields **sanitized** on the way in or out, not
  trusted. Plugin-supplied head/markup treated as untrusted and escaped.
- **Control:** escape-by-default templating; a vetted sanitizer for any HTML that
  must survive; never `echo` raw request/DB/plugin strings into markup.

## 5. Mass assignment / over-posting

A hidden or unexpected field in a write payload sets something it shouldn't —
`role`, `owner`, `status`, `published_at`, another collection's field, an
arbitrary key into the entry JSON blob.

- **Surfaces:** `Content/EntryInput.php`, `Content/EntryService.php`,
  `Content/Validator.php`, `Admin/EntriesController.php`, `Api/ApiController.php`
  (when writes land), `Auth` user edits.
- **Check:** writes bind to an **explicit allow-list of fields** for that
  collection/actor; unknown keys rejected or dropped, never merged blindly into
  the stored blob. Privileged fields (status/lifecycle/ownership/role) require a
  separate authorized path, not the generic field setter.
- **Control:** allow-list binding per collection + actor; privileged transitions
  behind their own authorized service methods.

## 6. Cross-site request forgery (CSRF)

- **Surfaces:** `Http/Csrf.php`, `Http/Middleware/AuthMiddleware.php`, every
  state-changing admin `POST`, and the **backlogged plugin admin forms** (admin
  pages ship GET-only precisely to defer this).
- **Check:** every state-changing browser request carries and verifies a CSRF
  token; token tied to the session; `SameSite` cookies as depth. The API uses
  bearer tokens (not cookies) and is therefore CSRF-exempt **only** as long as it
  never accepts the session cookie for auth — verify that boundary holds.
- **Control:** token on all cookie-authenticated writes; keep API auth strictly
  bearer-token, never ambient cookie.

## 7. Path traversal / arbitrary file access

Nimbus **has already had** a traversal-regex bug here — treat this surface as
guilty until proven safe.

- **Surfaces:** `Site/SiteController.php` (`/theme/assets/{path*}` route),
  template resolution in `View/View.php`, `Media/*` upload/serve paths, any
  config-driven file include.
- **Check:** path params can't escape the intended root (`../`, encoded `..%2f`,
  absolute paths, null bytes, symlinks). Type allow-list for served assets (no
  `.php`). Resolve then verify the real path is **inside** the allowed base.
- **Control:** allow-list characters, reject traversal sequences, `realpath`
  containment check against the base dir, extension allow-list.

## 8. Unsafe file upload

- **Surfaces:** `Media/MediaUploader.php`, `Media/UploadError.php`,
  `Admin/MediaController.php`, `Content/FieldTypes/MediaType.php`.
- **Check:** type decided by **content sniff**, not the client extension or
  `Content-Type`; stored names **randomized** (no attacker-controlled path/name);
  **SVG scripts stripped** (SVG is an XSS vector); size/count limits; files served
  from a location that **cannot execute PHP**.
- **Control:** MIME-sniff allow-list, random storage names, SVG sanitize,
  non-executable storage path, size caps.

## 9. Open redirect

- **Surfaces:** `Site/SiteController.php` / the redirect manager
  (`config/redirects.php`), any post-login `return`/`next` parameter.
- **Check:** redirect targets are **relative or host-allow-listed**; no
  user-supplied absolute/`//evil.com` URL is followed.
- **Control:** allow-list destinations; force relative paths for
  user-influenced redirects.

## 10. Authentication & session weaknesses

- **Surfaces:** `Auth/Auth.php`, `Auth/Password.php`, `Auth/LoginThrottle.php`,
  `Http/Middleware/AuthMiddleware.php`, session config.
- **Check:** argon2id (or better) hashing; **session id rotates on login**
  (fixation); throttling/lockout on failed login; secure cookie flags
  (`HttpOnly`, `SameSite`, `Secure` on HTTPS); logout invalidates server-side;
  password reset / session revocation designed before they ship (backlogged).
- **Control:** keep the existing hardening; extend it to every new auth path;
  never weaken a flag "for local".

## 11. API token / secret handling

- **Surfaces:** `Api/ApiToken.php`, `Api/ApiTokenRepository.php`.
- **Check:** tokens generated with a CSPRNG and enough entropy; stored **hashed**
  (SHA-256+), never plaintext; compared with a **timing-safe** function
  (`hash_equals`); shown once on creation; support **expiry + revocation**; never
  logged, never placed in URLs/query strings.
- **Control:** CSPRNG + hash-at-rest + `hash_equals` + expiry/revocation; scrub
  tokens from logs and error output.

## 12. Plugin boundary abuse

A plugin is in-process PHP and cannot be sandboxed — the boundary is a
**contract**, enforced by review, not a wall.

- **Surfaces:** `Plugin/PluginContext.php`, `Plugin/PluginStorage.php`,
  `Plugin/*Registrar.php`, `Plugin/PluginLoader.php`.
- **Check:** the capability change doesn't hand a plugin a core connection, core
  tables, controllers, or a service locator (ADR 0001 / 0005 — own tables only).
  A throwing/hostile plugin is contained and rolled back, never partial. Any
  future core-data access follows the tiered contract (read-model / service +
  scope + audit), never raw core-table SQL.
- **Control:** keep `PluginContext` the *only* surface; new capabilities added
  one proven consumer at a time; core data behind operations, not tables.

## 13. HTTP / host / header / proxy

- **Surfaces:** `Http/TrustedProxies.php`, `Http/SecurityHeaders.php`,
  `Http/Request.php`, `Http/Response.php`.
- **Check:** client IP / proto trusted **only** from configured proxies (not raw
  `X-Forwarded-*`); host-header not used to build security-sensitive URLs
  (poisoning → password-reset links, cache); security headers present (CSP,
  `X-Content-Type-Options`, frame options); no header injection from user input
  into responses.
- **Control:** trusted-proxy allow-list; canonical host config; CSP + headers on
  every response; strip CR/LF from any user value that reaches a header.

## 14. Information disclosure

- **Surfaces:** error handling (`Http/HttpException.php`, `Api/ApiResponse.php`),
  logs, `Support/Config.php` / `Support/Env.php`.
- **Check:** production errors show a reference id, not stack traces / SQL /
  paths; drafts/scheduled/archived content is **indistinguishable from absent**
  to an unauthorized reader (no existence leak via status codes or timing);
  secrets never logged.
- **Control:** generic errors + server-side reference; uniform not-found for
  unauthorized-or-absent; secret-scrubbing in logs.

## 15. User-editable config → downstream-sink poisoning

- **Surfaces:** `Settings/SettingsRegistry.php` validators and every consumer of a
  setting value — `View/View.php` (HTML), `Api/OpenApi*` (`info.title` JSON),
  `Mail/NativeMailer.php` (subject header), `Auth/{Invitation,PasswordReset}Service.php`.
- **Check:** when a value that used to be config/env becomes admin- or MCP-editable,
  **enumerate every sink it reaches** and confirm each escapes/validates for its
  context — HTML, JSON, **mail headers**, SQL identifiers, filenames, URLs. A sink
  audited for one context (HTML-escape) is not safe in another (a CR/LF is inert in
  HTML but breaks a mail subject).
- **Control:** validate at the shared registry boundary (closes admin form + MCP at
  once); reject control chars **byte-wise** (`/[\x00-\x1F\x7F]/` — the `/u` modifier
  fails open on invalid UTF-8); escape per-sink at each consumer.
- **Sightings:** 2026-08-22 site-title (HTML/JSON XSS audit); SUP-2 (the mail subject
  the first audit missed) — the recurrence that promoted this to a standing check.

## 16. Derived / prefixed value overflowing a bounded column → 1406/500

- **Surfaces:** any user-influenced string that is *transformed* (slugified,
  prefixed, hashed-or-not, concatenated) and then stored in a fixed-width column —
  `nb_collections.handle`/`nb_fields.handle` (VARCHAR 80), `nb_migrations.migration`
  (191, `pluginId:name`), `nb_login_throttle.id` (190, `login-em:`/`pwreset-em:`),
  `nb_entries.slug`/`title`.
- **Check:** the length bound must be enforced on the **derived** value at its
  **column width**, not on the raw input — a valid-looking input can derive an
  over-long stored value. Under `STRICT_TRANS_TABLES` an overflow is a 1406 →
  uncaught 500 (often on an **unauthenticated** path — login, install, migrate).
- **Control:** validate/clamp the derived value against the column width, or key on
  a fixed-length **digest** of an unbounded input (`hash('sha256', …)`), or bound
  the raw input tightly enough that no derivation can overflow.
- **Sightings:** Slice F slug suffix-overflow; Slice G collection handle-80 (the
  derived handle, not the name); PLUG-2 plugin-id → migration name; AUTH-2 throttle
  key → the recurrence (3rd) that promoted this to a standing check. **A "mirror the
  existing pattern" instruction can faithfully copy this bug** — check the mirror.

---

## Cross-cutting reminders

- **Fail closed.** Unknown scope, unknown role, unparseable input → **deny**.
- **Compose the mediums.** Three Medium findings that chain (info leak + missing
  rate limit + weak reset) are a High. Rate the chain, not just the parts.
- **New endpoint = full pass.** Every new route/API resource re-runs 1–6, 11, 14.
- **Smallest control wins.** A bound parameter beats a WAF rule; an object-level
  check beats a new framework.
