# Admin controllers & views — audit findings

**Domain:** `src/Admin/**` + the admin templates under `src/View/themes/nimbus/templates/**`.
The admin surface is in strong shape overall: every state-changing action carries CSRF +
a capability gate, subset-only guards every grant path, and templates escape at every
sink I traced. The real problems are at the seams: the ADR-promised `{handle}:read`
gate never landed on the browsing surfaces, the MCP user tools still write the dead
legacy `users.role` column (a revocation that silently doesn't revoke), and role
*deletion* skipped the "no touching a superior role" rule that create/edit enforce.

---

### ADMIN-1 · Admin content browsing ignores `{handle}:read` — ADR 0011's deny-by-default read gate does not exist
- **Priority:** P1
- **Type:** security
- **Severity (if security):** Medium
- **Where:** `src/Admin/EntriesController.php:85-116` (`index()` — no capability check for non-singleton lists), `src/Admin/CollectionsController.php:55-74` (`index()` — lists every collection to any session), `src/Admin/Controller.php:50-57` (nav comment codifies "content sections are visible to any signed-in user")
- **What:** ADR 0011 ("Implemented") and the `docs/ROLES.md` authorization matrix promise that a collection a role doesn't grant is "not in the nav, forbidden on a direct hit" (`{handle}:read`), but the admin enforces read-gating nowhere: any signed-in user — including one whose roles grant **zero** content capabilities — can browse every collection's full entry list.
- **Evidence:** create a custom role with only `media:read`+`media:write` (the matrix's own example row: "Read collections ❌"), assign it to a user, sign in as them, GET `/admin/collections/posts/entries` → 200 with every entry's title, slug, status (drafts included) and the first two fields' data rendered (`entries/index.php:48-80`). A user with **no roles at all** gets the same. Only *writes* are gated (`requireManage`). Tokens with the same capability set are denied the equivalent API read — the two principals the shared `Authorizer` was built to unify are judged differently on this surface. `RolesEnforcementTest` covers only write denial; no test asserts the matrix's read column against the admin.
- **Fix:** gate `EntriesController::index` (list + singleton view) on `Gate` read for the handle (`Authorizer::can(caps, handle, 'read')`; keep the legacy-fallback behavior un-tightened), filter `CollectionsController::index` rows to readable collections, and hide unreadable ones from links. Behavior-preserving for seeded installs (admin/editor/author all hold `*:read` — the Slice-3 ledger's "don't silently tighten" concern); only deliberately-narrow custom roles tighten, which is ADR 0011's stated intent. If instead the team decides browsing stays login-gated, downgrade ADR 0011 + the ROLES.md matrix and record the accepted risk — today the docs promise a control that does not exist.
- **Effort:** M

### ADMIN-2 · MCP `set_role`/`create_user` write the dead legacy `users.role` column — demoting an admin silently doesn't revoke
- **✅ RESOLVED** (Slice A, 2026-08-23) — MCP tools assign roles + subset-only guard; last-admin counted by role assignment.
- **Priority:** P1
- **Type:** security
- **Severity (if security):** High
- **Where:** `src/Mcp/UsersToolset.php:94-152` (cross-domain — found while auditing `UsersController` parity; flagging here so it isn't lost between domain files) with `src/Auth/RoleRepository.php:132-141` and `src/Auth/Gate.php:44-61`
- **What:** since the Roles Slice-3 enforcement flip, a seeded install authorizes users from `nb_user_roles` only (no per-user fallback — `capabilitiesForUser` unions role rows and nothing else), but the MCP user tools still read/write `users.role` (`Permissions::ROLES`), so they manipulate a column with no authority.
- **Evidence:** seeded install, user X holds the admin role in `nb_user_roles`. An agent with `users:write` calls `set_role {email: x, role: "editor"}` → `users.setRole()` updates `users.role`, the tool returns success, `list_users` now shows "editor" — but `Gate`/`Authorizer` still read `nb_user_roles`, so X **remains a full admin**. The operator believes a compromise was contained; nothing was revoked. Conversely `create_user {role: "admin"}` creates a user with zero `nb_user_roles` rows → a "created admin" who can do nothing. The `set_role` last-admin guard counts `countByRole('admin')` on the same dead column, so it protects the wrong invariant. Also cosmetic: `list_users` reports "author" for users the admin UI made admin (UI hardcodes the legacy column to `'author'`, `UsersController.php:121`).
- **Fix:** rebuild the MCP user tools on the roles model: `set_role`/`create_user` resolve a role by name from `RoleRepository`, sync `nb_user_roles`, apply the same subset-only guard as `UsersController::firstUngrantableRole`, and base the last-admin check on `assignedUserCount`. `list_users` reports assigned roles. Regression test: MCP demotion of a role-held admin actually strips the capability (assert via `Gate`), through the kernel.
- **Effort:** M

### ADMIN-3 · Role *delete* skips the subset-only guard that role *edit* enforces — a lesser manager can destroy a superior role
- **Priority:** P2
- **Type:** security
- **Severity (if security):** Medium
- **Where:** `src/Admin/RolesController.php:145-160` (`destroy()`), contrast `update()` lines 113-119
- **What:** `update()` refuses to touch a role that grants a capability beyond the actor ("no nerf-by-edit / no touching a superior role"), but `destroy()` checks only `isSystem` — so a non-admin holding `roles:write` can delete any *custom* role regardless of what it grants.
- **Evidence:** admin creates custom role "Ops" with `users:write`+`schema:write` and assigns it to a colleague; a user whose only management cap is `roles:write` POSTs `/admin/roles/{opsId}/delete` (CSRF from their own session) → role deleted, `nb_user_roles` assignments cascade away, any role-bound token's capabilities drop (`role_id` → NULL → deny). Not an escalation (fails in the closed direction) but a sabotage/denial primitive the model's own stated rule forbids, and it silently strips a *superior* user's access — exactly what `update()` blocks.
- **Fix:** in `destroy()`, reject when `firstUnheld($role->capabilities) !== null` (same message shape as edit). Regression test alongside `RolesAdminTest`: a `roles:write`-only actor cannot delete a role granting `users:write`; an admin still can.
- **Effort:** S

### ADMIN-4 · Singleton collection: a non-manager gets an infinite redirect loop instead of a denial
- **Priority:** P2
- **Type:** correctness
- **Where:** `src/Admin/EntriesController.php:90-94` (`index()` singleton branch) + `:356-361` (`requireManage()` aborts to the entries index URL)
- **What:** for a singleton, `index()` immediately calls `requireManage()`, whose denial redirects to `/admin/collections/{handle}/entries` — the very route that was just denied — so the browser loops until `ERR_TOO_MANY_REDIRECTS`.
- **Evidence:** seeded install, singleton collection `homepage`, user whose roles grant no `homepage:write` (e.g. a custom role, or an author not granted it). The collections index (visible to everyone, `collections/index.php:29,36`) links the singleton's "Edit" → GET `/admin/collections/homepage/entries` → 302 to itself → 302 → … browser error page. Non-singleton denials don't loop only because the list happens to be ungated (ADMIN-1). No test covers a non-manager hitting a singleton index (`EntryRoutesTest` covers manager paths).
- **Fix:** in `requireManage`, abort to `/admin/collections` (or gate the singleton view on read per ADMIN-1 and show a read-only rendering). One kernel test: non-manager GET on a singleton index → 302 to a *different* URL.
- **Effort:** S

### ADMIN-5 · Two fields with colliding handles: 500 on edit, wrong error on create
- **Priority:** P2
- **Type:** error-handling
- **Where:** `src/Admin/CollectionsController.php:221-260` (`fieldDefs()` — no duplicate-handle check), `src/Content/CollectionRepository.php:129-166` (`syncFields` INSERTs both), `src/Content/CollectionService.php:26-42` (create's catch mislabels it)
- **What:** the field builder derives handles from labels and never de-duplicates, so two rows that normalize to the same handle (`"Size!"` and `"Size?"` → `size`) violate `uq_field (collection_id, handle)`.
- **Evidence:** *edit* an existing collection adding two new fields labeled "Size!" and "Size?" → duplicate-key `PDOException` escapes `CollectionService::update` (no catch) → 500 error page, all builder work lost. On *create*, the catch maps ANY duplicate-key to `DuplicateHandle` → the form says "The handle "posts" is already taken. Pick another." — blaming the collection handle for a field collision (misleading, though nothing is saved). A subtler variant: a new field whose derived handle equals an existing field's handle silently *overwrites* that field's definition (`syncFields` treats it as an update) — data-shape change with no warning.
- **Fix:** in `validateDraft`/`fieldDefs`, reject duplicate field handles with a per-row message before calling the service (the DB stays the invariant authority; this is the friendly-feedback layer the principles call for). Test: duplicate labels re-render the form with an error, on both create and update.
- **Effort:** S

### ADMIN-6 · Malformed `published_at` in an entry save → uncaught exception → 500
- **Priority:** P2
- **Type:** error-handling
- **Where:** `src/Admin/EntriesController.php:322-327` (`publishedAtInput()` passes the raw string), `src/Content/Publication.php:84-96` (`resolvePublishedAt` → `new DateTimeImmutable($requested)` throws)
- **What:** the submitted publish time is handed to `DateTimeImmutable` unvalidated; a non-date string throws (`DateMalformedStringException`) with no catch on the path, so the save 500s instead of re-rendering the form with a field error.
- **Evidence:** POST `/admin/collections/posts/entries` with `status=published&published_at=banana` (any non-browser client, or a browser that doesn't enforce `datetime-local`) → 500 reference-id page; the entry the editor typed is lost. All other entry inputs (status, fields) are normalized or allow-listed — this is the one raw pass-through.
- **Fix:** validate the format in `publishedAtInput()` (or catch in `Publication::resolvePublishedAt` and return a validation error through the existing `SaveEntryResult` path) → re-render with `errors['published_at']`. Fix belongs jointly to admin + content-db; filing here because the admin form is the reachable surface. Test: hostile `published_at` re-renders with an error, entry preserved.
- **Effort:** S

### ADMIN-7 · A failed or expired invite cannot be re-sent — the UI's own advice ("re-invite") is impossible
- **Priority:** P2
- **Type:** product-gap
- **Where:** `src/Admin/UsersController.php:84-130` (`store()` rejects existing emails; routes have no resend), `src/View/themes/nimbus/templates/users/index.php:19` (the `invited-nomail` message says "then re-invite")
- **What:** when an invite email fails (`invited-nomail`) or expires (72 h TTL), the pending user exists with an unusable random password, but there is no way to send a new invite: re-submitting the form hits `emailExists` → "A user with email … already exists", and the users page offers no per-user "resend invite" action.
- **Evidence:** create a user with blank password on an install with broken mail → `?msg=invited-nomail` tells the admin to fix mail "then re-invite" → any attempt errors. The only escape is the user self-serving `/admin/forgot` (which does work for a pending user) — undiscoverable, and the admin was explicitly told to do something the product can't do.
- **Fix:** add `POST /admin/users/{id}/invite` (CSRF + `users:write` + only for users, reusing `InvitationService::sendInvite` — re-invite already supersedes prior tokens per the ledger) with a "Resend invite" row action; correct the `invited-nomail` copy. Standing MCP check: the deferred `invite_user` MCP tool is already recorded in ROADMAP (line 90) — keep this UI-only slice consistent with that record.
- **Effort:** M

### ADMIN-8 · No length caps on admin text inputs — over-long values 500 under STRICT_TRANS_TABLES (verified on this stack)
- **Priority:** P2
- **Type:** error-handling
- **Where:** `src/Admin/UsersController.php:89-121` (name→VARCHAR(120), email→191), `src/Admin/RolesController.php:81-95` (name→80), `src/Admin/TokensController.php:99-101` (name→120), `src/Admin/CollectionsController.php:175-207` (name→120, description→255, field label→120), `src/Admin/EntriesController.php:299-320` (title→255, slug→191), `src/Admin/MediaController.php:85` (alt→255)
- **What:** no admin form validates length; MySQL runs `STRICT_TRANS_TABLES` (verified live: `SELECT @@sql_mode` on the running container), so an over-long value raises "Data too long" → an unhandled `PDOException` → 500, instead of a friendly validation message.
- **Evidence:** POST `/admin/users` with a 200-char name (or `/admin/roles` with a 100-char role name, or an entry with a 300-char title) → 500 reference-id page; on the entry form all typed field content is lost. No leak (the error handler shows a reference id) — purely an editor-hostile failure mode on every text input in the domain.
- **Fix:** a tiny shared guard (`Str::maxLen`/validator check) applied per form with the column limits, returning the standard per-field error. Do it once, alongside ADMIN-5/6, as one "admin form validation hardening" slice; tests per surface for the boundary value.
- **Effort:** M

### ADMIN-9 · Roles management has no MCP surface and no recorded deferral — the standing MCP check is unmet
- **Priority:** P2
- **Type:** architecture
- **Where:** `src/Admin/RolesController.php` + `src/Admin/UsersController.php` (role assignment) vs `src/Mcp/*` (no RolesToolset); ROADMAP MCP arc ("an agent runs the **entire** CMS — content, schema, media, users, tokens, settings")
- **What:** creating/editing roles and assigning roles to users exist only in the admin UI. ADR 0009 / the review-loop standing check require every management action to be MCP-reachable *or* its deferral recorded (ledger/roadmap); I found no such record for roles (the Slice-5 sweep's "roles have no MCP surface" is stated as a security observation, not a deferral decision).
- **Evidence:** an agent with `admin` (or a future `roles:write`) token cannot compose a role or assign one over MCP — the human UI is silently the only way, which is exactly what the standing check forbids; the roadmap's "entire CMS" claim is false for this capability. Blocks the ADMIN-2 fix from being fully agent-operable too (an agent can't grant the role it would now need to assign).
- **Fix:** either build a `RolesToolset` (list/create/update/assign, gated on `roles:write`, subset-only via the same `Gate::holds` predicate, audited, non-enumerating — the pattern is established) or record the deferral explicitly in ROADMAP/the decision ledger with a revisit trigger. The recording is the cheap, immediately-compliant option.
- **Effort:** S (record) / L (build)

### ADMIN-10 · Free-text `?err=`/`?msg=` reflection lets a crafted link paint arbitrary text into admin notices
- **Priority:** P3
- **Type:** security
- **Severity (if security):** Low
- **Where:** `src/Admin/MediaController.php:64-66`, `src/Admin/UsersController.php` / `RolesController.php` / `TokensController.php` (all pass `$req->query('err')` straight to the view), `entries/index.php:31` + `collections/index.php:12` (`$e(ucfirst($flash))` renders arbitrary `?msg=`)
- **What:** error/flash text travels via the query string and is re-rendered from whatever the query contains, so an attacker-crafted link displays chosen text inside a trusted admin alert box. Escaped by `View::e` everywhere (no XSS), but it's a social-engineering aid, and it's inconsistent — the newer surfaces (settings, oauth, tokens/users/roles *flash*) already use fixed-key → fixed-message maps.
- **Evidence:** send an admin `https://site/admin/media?err=Your%20session%20is%20out%20of%20date%20—%20sign%20in%20at%20nimbus-support.example` → red error banner with that text renders inside the authentic admin UI. Same for `/admin/collections/posts/entries?msg=anything` (green success banner).
- **Fix:** finish the migration the newer pages started: pass error *codes* and map them to fixed strings in the template (the media/users/roles/tokens error paths each have a small finite message set); for the one genuinely dynamic message (media "In use by: …"), keep it server-rendered rather than round-tripped through the URL (render the error state directly instead of redirecting, or stash it in the session flash).
- **Effort:** S

### ADMIN-11 · Entries index shows a "Fields" button to users without `schema:write` — dead link
- **Priority:** P3
- **Type:** product-gap
- **Where:** `src/View/themes/nimbus/templates/entries/index.php:26` (unconditional), `src/Admin/CollectionsController.php:78` (route requires `schema:write`)
- **What:** the ADR 0011 "no dead links" rule (nav, dashboard cards, collections index all honor it) is broken by the one remaining ungated affordance: every viewer of an entry list sees "Fields", and a non-schema user who clicks it is silently bounced to the collections index.
- **Evidence:** sign in as seeded `editor` → open any entry list → "Fields" button renders → click → 302 to `/admin/collections` with no explanation.
- **Fix:** pass `canSchema` (`$this->gate->can('schema','write')`) from `EntriesController::index` and wrap the button, exactly as `collections/index.php` does with `$isAdmin`.
- **Effort:** S

### ADMIN-12 · Crafted array-shaped field-builder input → TypeError 500 in the collections form
- **Priority:** P3
- **Type:** error-handling
- **Where:** `src/Admin/CollectionsController.php:236-238` (`fieldDefs()`: `$row['type']` / `$row['handle']` passed un-coerced)
- **What:** `fieldDefs()` guards the outer shapes (`is_array($fields)`, `is_array($row)`) but passes nested values straight into `string`-typed calls — `$this->types->has($type)` (`FieldTypeRegistry::has(string)`) and `Str::handle(...)` — so an array where a scalar is expected throws a `TypeError`.
- **Evidence:** an authenticated `schema:write` user POSTs `/admin/collections` with `fields[0][label]=x&fields[0][type][]=text` → `TypeError: has(): Argument #1 ($type) must be of type string, array given` → 500. Only reachable by a schema admin (no boundary crossed) — robustness, not security.
- **Fix:** coerce like the entry path does: `$type = is_string($row['type'] ?? null) ? $row['type'] : 'text';` and the same `is_string` guard before `Str::handle`. One request-shape test.
- **Effort:** S

### ADMIN-13 · Media library scales badly: no pagination, no alt-text editing, and the entry form loads the whole library
- **Priority:** P3
- **Type:** product-gap
- **Where:** `src/Admin/MediaController.php:58-69` (`media->all()`), `src/Admin/EntriesController.php:238-243` (media picker loads `all()`), no update route for alt (`routes()` lines 49-56); also `src/Admin/UsersController.php:68-71` (`rolesForUser` per user — N+1, harmless at admin-list scale but the only grouped-counts exception left)
- **What:** entries and collections got the listing-hardening pass (pagination, grouped counts); media did not — the library page and every entry form with a media field render/query the entire library, and alt text (the one editorial attribute) is settable only at upload, never editable after.
- **Evidence:** an install with a few thousand media rows renders them all on `/admin/media` and builds a several-thousand-option `<select>` per media field on every entry form; fixing a typo'd alt text requires delete + re-upload — which the in-use guard may rightly refuse for a referenced image, making the alt effectively immutable.
- **Fix:** paginate `/admin/media` with the established `PER_PAGE` pattern; add `POST /admin/media/{id}` (alt update; CSRF + `media:write`); a searchable/lazy picker can wait for the admin-experience redesign. MCP parity note: `update_media` alt-edit should land with the same slice or be recorded as deferred.
- **Effort:** M

### ADMIN-14 · Small correctness paper-cuts: relation target unvalidated server-side; token lifecycle reports success for nonexistent ids
- **Priority:** P3
- **Type:** correctness
- **Where:** `src/Admin/CollectionsController.php:253-256` (`$options['target']` stored as submitted), `src/Admin/TokensController.php:198-210` (`lifecycle()` never checks the id exists)
- **What:** (a) the relation field's `target` is a raw request string — the UI offers a dropdown of real handles, but the server stores anything, and a bogus/deleted target silently yields an empty picker and dead relations; (b) `POST /admin/tokens/999/revoke` no-ops in the repository yet flashes "Token revoked." — a false success (cosmetic; no authorization impact since all lifecycle verbs are gated the same).
- **Evidence:** (a) POST a collection with `fields[0][type]=relation&fields[0][target]=nope` → saved; the entry form renders an empty relation picker with no diagnostic (`renderForm` maps a missing handle to `[]`). (b) revoke a just-deleted token id from a stale tab → green "Token revoked." for a token that never existed.
- **Fix:** (a) validate `target` against existing handles in `validateDraft` (mirror `scopesFrom`'s allow-list approach) and error the row; (b) have the repository return affected-rows and flash an error when 0.
- **Effort:** S

---

## What's solid

The heavy security machinery holds up under adversarial reading. Every
state-changing action in the domain has CSRF + the correct capability gate
checked in the handler (nav gating is cosmetic, exactly as the Slice-5 sweep
claimed — I re-traced all of it: collections→`schema:write`, media per-action
`media:read`/`write`, users/roles/tokens per-action, plugins→`admin`, entry
writes→`manages`, settings site→`settings:write`, theme self-only). The
subset-only predicate really is one shape across all four grant surfaces
(`firstUnheld`/`firstUngrantableRole`/`firstUngrantable` over `Gate::holds`),
checked over the full granted set, before any write, server-side (dropdown
filtering is convenience). The token mint's nonce/plaintext discipline
(render-once, never a URL/flash/log, nonce consumed only after validation) is
implemented exactly as documented. Templates escape at every sink I checked —
including the token secret, capability summaries, OAuth labels, and the CSP
nonce — and the fixed-key flash maps on the newer pages are the right pattern
(ADMIN-10 just asks the older pages to catch up). The password-reset/invite
and OAuth controllers match their ledger claims line-for-line (purpose-scoped
tokens, no-referrer, throttle parity, generic responses, hardcoded redirects).
