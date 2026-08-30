# 21. Content preview — authorized draft viewing (rendered + headless)

- **Status:** Accepted; implemented. A core capability on the ROADMAP ("Preview
  API — draft-preview tokens"; "preview mode").
- **Date:** 2026-08-30
- **Related:** [ADR 0002](0002-publication-lifecycle.md) (the liveness rule this is
  the authorized inverse of), [ADR 0011](0011-authorization.md) (capability checked
  at issue). Mirrors the stored-hashed-token pattern of `nb_password_resets`.
- **Reviewed by:** `nimbus-review-loop` (classified Core) and
  `nimbus-security-review` (raised the cache-poisoning and token-scope findings) —
  before build.

## Context

An editor could save a draft but had no way to *see* it as it will appear before
publishing — the public site and API both return only live entries
(`Publication::isLive`, ADR 0002), and a draft URL 404s identically to an absent
one ("nothing to distinguish leaks"). "Preview before publish" is a universal CMS
need, wanted by every site type, and it must work for both a PHP-themed site
(rendered) and a headless consumer (JSON).

## Decision

**One entry-scoped, short-lived, signed token, verified in one place, consumed by
both the rendered site and the API.**

- **Token** (`nb_preview_tokens`, `Nimbus\Content\PreviewTokens`): 256 bits of
  CSPRNG entropy; only its SHA-256 **hash** is stored; it binds `collection_id` +
  `entry_id`; TTL ~30 min; expiry enforced in the lookup; never logged. `resolve()`
  returns the one `(collection_id, entry_id)` it grants, or `null` — no other
  signal. Issued only after the caller's collection `:read` is checked; the token
  then carries an anonymous read grant for that **one** entry for its TTL.
- **Rendered** (`SiteController::show`): a valid `?preview=<token>` matching *this*
  entry's URL renders the non-live entry through the theme, with a core-injected
  "unpublished draft" banner (`PreviewBanner`, theme-agnostic like `DemoBanner`).
  Any invalid/mismatched token falls through to the normal live path — so a bad
  token leaks nothing and a stray `?preview` on a live URL still shows the
  published page. Respects `isPubliclyBrowsable` (a non-browsable collection has no
  rendered surface).
- **Headless** (`GET /api/v1/preview?token=…`): a **public** route *outside* the
  API's token-auth group, with its own resolver returning exactly the one entry as
  JSON. The preview token never enters the `TokenPrincipal` machinery, so it can
  never list, write, or read another entry.
- **Admin:** a "Preview draft" button on the saved-entry editor mints a token
  (CSRF + collection `:read`) and opens the entry's public preview URL.

### Security controls (from the security review — all blocking-or-tested)

1. **Cache poisoning → mass draft exposure (HIGH).** The page cache keys only on
   `page`, and `show()` 200s are cached — so a `?preview=` render would be stored
   under the public URL. **`Application::cacheKey()` returns null whenever a
   `preview` param is present**, and preview responses send `Cache-Control:
   no-store`. This is the load-bearing control.
2. **Token/principal confusion (HIGH).** The dedicated public preview route keeps
   the preview token out of the API-token path (see above).
3. **IDOR:** the token binds `collection_id`+`entry_id` and the request's slug must
   resolve to *that* entry — a token for A cannot render B.
4. **No-leak 404:** an invalid/expired/wrong token yields the identical existing
   `notFound()` (no preview-specific error page = no existence oracle).
5. **Token leakage:** `Referrer-Policy: no-referrer` + `no-store` + short TTL +
   hashed-at-rest + revocable-by-expiry. Query-param is the shareable channel by
   design; residual (server access log holds a short-lived hashed-lookup token) is
   an accepted Low. Preview responses are also `X-Robots-Tag: noindex`.
6. **Mint authz/CSRF:** admin POST under auth + `requireCsrf` + collection `:read`.

## Consequences

- Draft preview works uniformly for themed and headless installs from one token
  and one verify path. The headless preview route is the natural anchor for a
  future headless example.
- The token grants read of exactly one unpublished entry and nothing more; it is
  not an API token and cannot be widened.
- **Deferred:** an MCP tool to mint a preview link (agent-first, gated) — recorded,
  not built. Previewing unsaved in-editor changes (this previews the *saved*
  draft). A "copy link / copy headless URL" affordance on the editor.

## Alternatives considered

- **Stateless HMAC token** (no table): rejected — there is no general app signing
  key, and a stored token is revocable and matches the existing pattern.
- **`?preview=` on the authed `entries/show`**: rejected — risks conflating the
  preview token with an API principal; a dedicated public route keeps the scope
  structurally to one entry.
- **Session-only preview** (no shareable link): considered; the signed link was
  chosen so a preview can be shared with a non-logged-in reviewer and reused for
  the headless mode.
