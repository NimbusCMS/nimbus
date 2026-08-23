# Follow-ups discovered during slice work

Items surfaced by the Fable two-skill bursts while building the audit slices —
tracked here so the burn-down stays complete. Same format as the domain files.

### FU-1 · `roles:seed` re-run widens authority for placeholder users
- **Priority:** P2
- **Type:** correctness (security-adjacent)
- **Discovered:** Slice A review.
- **Where:** `src/Auth/RoleSeeder.php:60-65` (assigns every user the system role matching their legacy `nb_users.role`).
- **What:** MCP/admin-created users carry a least-privilege `nb_users.role='author'` placeholder while their real authority is `nb_user_roles`. The seeder is advertised idempotent/re-runnable, but a re-run assigns the `author` **role** to every placeholder user — silently widening authority (grants `*:read` + media).
- **Fix:** one-line guard — skip users who already hold any `nb_user_roles` row (only assign the legacy-derived role to users with zero assignments).
- **Effort:** S

### FU-2 · Management forms enumerate every collection handle
- **Priority:** P3
- **Type:** security (info-disclosure, Low)
- **Discovered:** Slice B security review (A3).
- **Where:** `src/Admin/TokensController.php` + `tokens/index.php` (scope checkboxes), `src/Admin/RolesController.php` (role form), `src/Admin/SettingsController.php` (site.home dropdown).
- **What:** a narrow `tokens:write` / `roles:write` / `settings:write` holder sees every collection handle in the form, even ones they can't read/act on (subset-only still blocks the actual grant). Display-only leak of names to semi-privileged management actors.
- **Fix:** offer only grantable/readable collections in these forms (align with the "offer only grantable" pattern already used elsewhere).
- **Effort:** M

### FU-3 · Dashboard shows aggregate counts to any signed-in user
- **Priority:** P3
- **Type:** security (info-disclosure, Low — aggregate, nameless)
- **Discovered:** Slice B security review (A4).
- **Where:** `src/Admin/AdminController.php` `dashboardPage()` — raw `COUNT(*)` of `nb_collections`/`nb_entries`.
- **What:** a zero-read user sees "Collections: 7" beside an empty (filtered) collections list, learning hidden collections exist (a count, not names) — a mild dent in the "unreadable == missing" property.
- **Fix:** scope the dashboard stats to readable collections, or accept as a documented residual (numbers only). Natural fit for the admin-experience redesign.
- **Effort:** S

### FU-4 · Collection handle can collide with a management capability name
- **Priority:** P3
- **Type:** correctness / security (Low, pre-existing)
- **Discovered:** Slice B reviews (A9).
- **Where:** `src/Admin/CollectionsController.php` `validateDraft` + `src/Mcp/SchemaToolset` `createCollection` (no reserved-handle check); interacts with `Authorizer::MANAGEMENT`.
- **What:** a collection named `media`/`users`/`tokens`/`settings`/`roles`/`schema` (or `admin`/`*`) is treated by `Authorizer` as a management resource — no content wildcard, no write⇒read — so `reads()`/`manages()`/the API judge it by management rules (a `*:read` role is denied it; a `media:read` holder is granted content-read of a collection named `media`). Pre-existing via `manages()`; Slice B extends it to reads.
- **Fix:** reject `Authorizer::MANAGEMENT ∪ {admin, *}` as collection handles at creation (admin form + `SchemaToolset`), one validation line each — closes the class for reads/manages/API at once.
- **Effort:** S

### FU-5 · No app-level request-body-size bound
- **Priority:** P3
- **Type:** security (DoS, defense-in-depth)
- **Discovered:** Slice F security review.
- **Where:** `src/Http/Request.php` `json()` (`file_get_contents('php://input')`, no cap).
- **What:** the entire request body is read + JSON-decoded before app validation runs. Slice F bounds what reaches the DB (per-field length, relation cardinality, column widths), but parse-time memory/CPU is guarded only by PHP `post_max_size` / MySQL `max_allowed_packet` — deployment config, not app.
- **Fix:** document the deployment ceiling (done in COMPATIBILITY), and optionally a small app-level body-size guard in the kernel if evidence warrants (proportionality: don't build a body-size middleware speculatively).
- **Effort:** S

### FU-6 · A field handle can collide with a reserved error-map key
- **Priority:** P3
- **Type:** correctness
- **Discovered:** Slice F platform review (relates to FU-4 / ADMIN-14 reserve-names family).
- **Where:** the entry error map keys `title`/`slug`/`published_at` alongside field handles; nothing reserves those names at schema-create (`CollectionsController::validateDraft`, `SchemaToolset`).
- **What:** a user-defined field with handle `published_at`/`title`/`slug` collides in the flat `{code,message}` error map, so its error could be shadowed by (or shadow) the entry-level one.
- **Fix:** reserve `title`/`slug`/`published_at` (with the `Authorizer::MANAGEMENT` names from FU-4) as disallowed field/collection handles at schema-create — one allow-list check, closes both families.
- **Effort:** S

### FU-7 · Hosted-analytics beacons need a CSP `connect-src` (or the proxy pattern documented)
- **Priority:** P3
- **Type:** product-gap / security (scope decision)
- **Discovered:** Slice H reviews (HTTP-1 / PLUG-5).
- **Where:** `src/Http/SecurityHeaders.php` CSP (`default-src 'self'`, no `connect-src`); the analytics head-contribution use case (PLUG-5).
- **What:** Slice H exposed the nonce so a plugin can emit a nonce'd analytics `<script>` — and under CSP L2+ a nonce'd external `<script src>` *loads* regardless of origin. But the site CSP has no `connect-src`, so the loaded script's `fetch`/`sendBeacon` to a third-party endpoint (Plausible/Fathom/GA event APIs) is blocked: the script runs, the event never sends. Self-hosted or reverse-proxied analytics (served from `'self'`) works fully today; GA additionally needs `'strict-dynamic'` for its chained injects.
- **Fix (decide, own review):** either an operator-config CSP source extension (an env allow-list feeding `connect-src`/`script-src`, opt-in, off by default — needs a security review of its own), or document the reverse-proxy pattern (Plausible's official proxy) as the supported path and leave the CSP tight. Do NOT widen the CSP without that review.
- **Effort:** S (docs) / M (config-driven CSP)
