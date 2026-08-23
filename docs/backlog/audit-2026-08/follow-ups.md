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
