# 17. Plugin public routes + webhooks (H4)

- **Status:** Accepted; implemented. The fourth and last of the plugin-boundary
  capabilities the Inventory + Commerce initiative named (H1 events, H2a
  capabilities, H2b MCP tools, **H4 routes**). Its consumer is Nimbus Commerce (a
  storefront's cart/checkout actions and payment webhooks), built next.
- **Date:** 2026-08-28
- **Related:** [ADR 0001](0001-plugin-contract.md) (the boundary that excluded
  routes until a real consumer arrived), [ADR 0012](0012-oauth-sso.md) (public
  routes in core — the precedent this generalises to plugins), [ADR 0007](0007-write-api.md)
  (the bearer-only, CSRF-exempt surface a webhook resembles).
- **Reviewed by:** `nimbus-review-loop` and `nimbus-security-review` — the exposure
  boundary made this a security-led review, before build.

## Context

The plugin boundary has never let a plugin serve a public URL — the same wall that
made OAuth SSO a core subsystem rather than a plugin (ADR 0012). It held while no
plugin needed one. Commerce breaks that: a storefront needs cart/checkout **actions**
(dynamic POSTs), and hosted payments need a **webhook** endpoint the provider calls
with a signed body. Neither is content, and neither is an admin or API route.

Opening routes to plugins is the highest-exposure capability yet — a plugin route
is reachable by anyone on the internet, unauthenticated — so the review's whole job
was the containment.

## Decision

A plugin registers public routes through a new registrar, always under a single
reserved prefix:

```php
$ctx->routes()->post('shop', '/webhook', $handleWebhook);   // POST /ext/shop/webhook
$ctx->routes()->get('shop', '/cart', $showCart);            // GET  /ext/shop/cart
```

Four controls make the exposure safe, three of them structural:

1. **A reserved `/ext/{namespace}/…` prefix.** Every plugin route lives here.
   `ext` is a reserved collection handle (alongside `api`/`uploads`/`theme`), so no
   content ever lives under it, and `/ext/{ns}/…` can never be a 1- or 2-segment
   content path — it *cannot* collide with the site's `/{collection}` catch-alls,
   structurally.
2. **Mounted between core and content.** The kernel registers plugin routes *after*
   every core controller — so a plugin can never shadow `/admin` or `/api` (those
   win by first-match) — and *before* the content catch-all, so `/ext/…` resolves
   to the plugin. The registry is frozen at plugin-load.
3. **A per-plugin-unique namespace**, bound by the registrar to the loader-verified
   id. A second plugin claiming a namespace fails its load; a namespace and its
   routes roll back with a failed plugin. `..` and stray characters in a path are
   refused.
4. **No ambient authority.** Plugin routes are outside the admin auth middleware and
   carry no automatic CSRF. An admin session cookie grants nothing there (parity
   with the bearer-only API), so the plugin owns its authentication: a webhook
   verifies the provider's HMAC over `Request::rawBody()` (added here — the exact
   bytes, before parsing); a privileged action checks a token. The global security
   headers + CSP wrap every response already, plugin routes included.

## Consequences

**Enables.** The Commerce storefront actions + payment webhooks, and the same seam
for any plugin public endpoint (a form handler, an OAuth-style callback, an
incoming-webhook receiver). General beyond Commerce — recommended on its own.

**Costs / makes harder.** Plugin URLs carry the `/ext/{namespace}/` prefix — not
the prettiest for a public page, but these are actions and machine endpoints;
SEO-facing pages stay content (collections + theme). A plugin serving a public
route is trusted in-process code (ADR 0001) — the boundary is a reviewed contract,
not a sandbox, and this capability widens what that trust can reach, which is why
the namespace/prefix containment is structural rather than advisory.

**Deferred, recorded as follow-ups:**
- **Rate limiting is the plugin's job in v1.** A blanket per-route limit would drop
  legitimate webhook bursts from a payment provider; the `RateLimitMiddleware`
  primitive exists for a plugin (or Commerce) to opt into where it makes sense.
- **A signature-verification helper** (HMAC + `hash_equals`) could be offered so
  every webhook plugin doesn't re-implement it; deferred until a second consumer
  beyond Commerce shows the shape.

**Debt.** Acceptable and low. Additive (`routes()` alongside the other registrars),
reuses the existing Router with no new public method, and the containment is
structural (reserved prefix + mount order + unique namespace). Tests cover the
`/ext/` prefixing, namespace uniqueness + shape, path rejection (`..`, bad method),
the mount-order (a plugin route is served and never shadows content, a wrong method
is a 405), and the load-rollback tripwire.
