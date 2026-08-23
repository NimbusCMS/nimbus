# Auth, sessions & authorization — audit findings (2026-08-22)

Reviewed `src/Auth/**` (Auth, Password, LoginThrottle, Gate/Authorizer/UserPrincipal,
Role/RoleRepository/RoleSeeder, AccountTokenService, PasswordResetService/Repository,
InvitationService, and the whole `OAuth/**` subsystem), the two auth middlewares
(`AuthMiddleware`, `ApiAuthMiddleware`), `Csrf`, the session bootstrap in
`Application::startSession`, and `Request::ip()` as it feeds throttling — cross-read
against ADRs 0006/0011/0012 and both ledgers.

**Overall health: strong.** The high-stakes surfaces the ledgers describe hold up
under re-reading: OAuth Phase 1 (state single-use, PKCE S256, provider binding,
link-bound-to-session-user, unknown-identity reject, no-steal-on-UNIQUE, open-redirect
guard on `next`) is genuinely well-built and well-tested; the token/reset/invite
hashing, single-use consume, and purpose isolation are correct; the `Authorizer`
management-wildcard-immunity and subset-only granting are consistent. **No Critical
or High.** The findings below are one Medium enumeration/timing gap, one Medium
throttle-coverage gap, and a cluster of Low/product/test items — mostly consistency
debt where the login path lags the newer, more careful reset path.

---

### AUTH-1 · Login is a user-enumeration + credential-probing timing oracle (no equal-work on unknown email)
- **Priority:** P2
- **Type:** security
- **Severity:** Medium
- **Where:** `src/Auth/Auth.php:24-33` (`attempt()`); reached via `src/Admin/AdminController.php:124-145` (`POST /admin/login`)
- **What:** `attempt()` returns immediately when the email is unknown, but runs a full argon2id `password_verify` when the email exists — a large, single-sample-measurable timing difference that reveals whether an account exists.
- **Evidence:** For an unknown email the code path is `selectOne` → `$row === null` → `return false` (~1 ms, one indexed SELECT). For a known email it runs `Password::verify()` → `password_verify()` against an argon2id hash (tens of ms by design). An attacker POSTing to `/admin/login` with a throwaway password and timing the response distinguishes registered admin emails from non-registered ones on a single request each. This is exactly the leak the password-reset flow was hardened against — `PasswordResetService::request()` mints a token even for a non-existent account "so the residual timing signal is not sampleable" — but the login path does no equalizing work. The IP throttle (5/IP) only slows, not closes it: the delta is reliable at one sample, and IPs are cheap.
- **Fix:** In `attempt()`, when the row is absent, run a `password_verify()` against a fixed dummy argon2id hash (a class constant) before returning false, so both branches do one hash. Same one-line pattern the reset flow already uses in spirit.
- **Effort:** S

### AUTH-2 · Login throttle is IP-only — no per-account lockout, so single-account brute force / spraying from many IPs is unthrottled
- **Priority:** P2
- **Type:** security
- **Severity:** Medium
- **Where:** `src/Admin/AdminController.php:127-145` (throttle keyed on `$req->ip()` only); `src/Auth/LoginThrottle.php`
- **What:** Failed logins are counted per client IP only; there is no per-email/per-account throttle, so an attacker distributing attempts across IPs can grind a single known admin account without ever tripping a lockout — while the newer reset flow throttles by IP **and** target email.
- **Evidence:** `login()` uses `$key = $req->ip()` and `recordFailure($key)`. Contrast `PasswordResetController::sendLink()` which throttles both `pwreset-ip:<ip>` and `pwreset-em:<email>`. The login page has no `pwreset-em:`-equivalent second key, so 1000 attempts against `admin@site` from 200 IPs (5 each) never lock the account. Combined with AUTH-1 (enumerate a valid email first) and the 8-char password floor (AUTH-3), this is a practical offline-free online attack; argon2id cost is the only remaining brake. Also note: behind a reverse proxy with `TRUSTED_PROXIES` unset, every request keys to the proxy IP — one attacker locks out all users (DoS) and legitimate per-user throttling is impossible; that misconfig case is an operational footgun worth a hardening note.
- **Fix:** Add a second throttle key on the submitted email (mirror the reset flow: `login-em:<lower(email)>`), checked and recorded alongside the IP key, with a comparable threshold. Keep the IP key for flood control.
- **Effort:** S

### AUTH-3 · Weak-password floor is 8 chars + a 4-word denylist, but the reset/accept UI promises "at least 12 characters"
- **Priority:** P3
- **Type:** correctness (+ product-gap)
- **Where:** `src/Auth/Password.php:34-38` (`isWeak`, `strlen < 8`, denylist of 4); message mismatch in `src/Admin/PasswordResetController.php:119,159` and `src/View/themes/nimbus/templates/reset.php:44`, `accept.php:45` ("at least 12 characters")
- **What:** The enforced floor is 8 non-default characters, but the password-reset and invite-accept screens (label + error) tell the user "at least 12 characters"; a user can set an 8-char password despite the UI's claim, and the whole product's strength policy is a length check plus a 4-entry denylist.
- **Evidence:** `isWeak()` returns true only for `strlen($plain) < 8` or one of `['password','admin','123456','changeme']`. So `"aaaaaaaa"` (8 chars) is accepted at `/admin/reset`, yet that form states 12. `UsersController` and the MCP toolset correctly say "at least 8" — three surfaces, two different numbers. The lax policy also directly amplifies AUTH-2 (small brute-force keyspace for a determined online attacker).
- **Fix:** Pick one floor and make copy match code. Cheapest: change the reset/accept label + error strings to "at least 8". If a stronger policy is wanted for release, raise `isWeak`'s floor (e.g. 12) in one place and align every message — but that is a policy decision, not a copy fix.
- **Effort:** S

### AUTH-4 · Two sources of truth for "who is an admin" — divergent last-admin-lockout guards across surfaces
- **✅ RESOLVED** (Slice A, 2026-08-23) — both surfaces count `assignedUserCount(admin)`; dead `countByRole` removed.
- **Priority:** P2
- **Type:** correctness (security-adjacent)
- **Where:** `src/Admin/UsersController.php:117,152-158` (create hardcodes `users.role='author'`; last-admin guard counts the **admin role assignment** `nb_user_roles`), vs `src/Mcp/UsersToolset.php:146` (last-admin guard uses `countByRole('admin')` on the legacy `nb_users.role` column). `Auth::user()` still hydrates `User::role` from `nb_users.role`.
- **What:** Post-seed authority lives in `nb_user_roles`, but `UsersController::store()` always writes `nb_users.role = 'author'` for every admin-created user regardless of the roles it assigns, so `nb_users.role` and the roles table disagree — and the two "don't demote the last admin" guards read different columns, so one surface can demote the last *effective* admin while believing another admin still exists.
- **Evidence:** Create a user through `/admin/users` and assign it the `admin` **role**: it is a real admin (Gate reads `nb_user_roles`), but `nb_users.role` stays `'author'`, so `UserRepository::countByRole('admin')` does not count it. If that becomes the only admin and someone calls the MCP `set_role` on the original admin, `UsersToolset` (line 146) sees `countByRole('admin') <= 1` based on the stale legacy column and can wrongly block or, in the mirror case, wrongly allow a demotion — the admin-UI guard (role-assignment count) and the MCP guard (legacy-column count) can disagree about whether "this is the only admin." The legacy column is also the seed source (`RoleSeeder`) and the un-seeded fallback (`Permissions::isAdmin`), so keeping it stale is a latent trap. (MCP `UsersToolset` itself is api-mcp's file, but the root cause — the admin UI never syncing `nb_users.role` to the assigned roles — is in this domain.)
- **Fix:** Make one column authoritative for the last-admin invariant. Simplest: when the admin UI assigns/removes the admin role, keep `nb_users.role` in step (write `'admin'`/`'author'`), or route both surfaces' last-admin check through the same predicate (count users holding the admin *role* via `RoleRepository::assignedUserCount`). Don't leave two counters over two columns.
- **Effort:** M

### AUTH-5 · OAuth `start` is a state-changing GET with no CSRF token (forced-flow initiation / session flow overwrite)
- **Priority:** P3
- **Type:** security
- **Severity:** Low
- **Where:** `src/Admin/OAuthController.php:60-88` (`GET /admin/oauth/{provider}/start`), `src/Auth/OAuth/OAuthService.php:56-70` (writes `$_SESSION['nimbus_oauth_flow']`)
- **What:** `start` mutates session state (mints+stores state/verifier/intent/uid) on a plain GET with no CSRF token, so a third-party page can force a logged-in admin's browser to begin (or restart) an OAuth flow, overwriting any in-progress `nimbus_oauth_flow`.
- **Evidence:** A cross-origin auto-navigation to `/admin/oauth/google/start?intent=link` runs while the admin is authenticated and rewrites the single-slot session flow key. The security-relevant replay/forgery of the *callback* is fully covered by the single-use, session-bound `state` and the link-bound-to-session-user check, so this is not account takeover — the impact is limited to nuisance flow-restart / clobbering a concurrent tab's flow, hence Low. Worth recording because `start` is the one auth mutation reachable without a token and the flow is stored in a single overwritable slot.
- **Fix:** Defense-in-depth only: this is acceptable as-is given `state`, but if tightened, gate `intent=link` starts behind a CSRF token (they already require an authed session), and/or key the session flow by a nonce so concurrent flows don't clobber. Record as an accepted Low if not fixed.
- **Effort:** S

### AUTH-6 · Test gaps: no equal-work login assertion, no per-account throttle, no admin-role-sync coverage
- **Priority:** P3
- **Type:** test-gap
- **Where:** `tests/Http/AuthRoutesTest.php`, `tests/Integration/LoginThrottleTest.php`, `tests/Http/UsersAdminTest.php`
- **What:** The behaviors behind AUTH-1/2/4 have no guarding tests, so a fix (or a regression) would be invisible to CI.
- **Evidence:** `AuthRoutesTest` covers happy login, wrong password, unknown email, CSRF, and **IP** throttle — but nothing asserts that an unknown vs known email does comparable work (AUTH-1), nothing asserts a single account is protected when the IP varies (AUTH-2), and `UsersAdminTest`/`RolesEnforcementTest` don't assert that assigning the admin role makes the user count as an admin for the last-admin guard (AUTH-4). The reset flow, by contrast, has `test_a_delivery_failure_still_looks_the_same` guarding its equal-work property — the login path deserves the same.
- **Fix:** With each fix add: an assertion that unknown-email login also runs a hash (or that timing/behaviour is indistinguishable); a throttle test that varies IP but fixes the email and asserts eventual lockout; a users test that a UI-assigned admin role satisfies the last-admin invariant on both surfaces.
- **Effort:** S

---

## What's solid (don't worry about these)

- **OAuth Phase 1 is genuinely well-built.** State is CSPRNG + session-bound + consumed-before-use (single-use), PKCE is S256(verifier), the callback binds to the start provider and, for links, to the initiating session user; unknown identities are rejected with no email fallback; a `UNIQUE` conflict returns "already linked" rather than stealing; `next` is open-redirect-guarded; TLS verification is hard-on and never disabled; the client secret never touches the front channel. All mirrored by `OAuthFlowTest`.
- **One-time credential tokens (reset + invite) are correct.** 256-bit CSPRNG, SHA-256 at rest, lookup-by-hash, atomic single-winner `consume` (`used_at IS NULL` affected-rows=1), purpose-scoped so reset/invite never interchange, strength-checked before consume, siblings invalidated on success, links built from `APP_URL` (no Host poisoning), `Referrer-Policy: no-referrer` on the pages.
- **The authorization core holds.** `Authorizer` is deny-by-default with management-capability wildcard-immunity; `Gate` memoizes, falls back to legacy `Permissions` only while un-seeded (not attacker-reachable — system roles are undeletable), and `holds()` drives a consistent subset-only rule; `TokenPrincipal`/role-union resolution denies on empty abilities.
- **Session & CSRF hygiene is right.** `session_regenerate_id(true)` on both `attempt()` and `login()`; strict-mode + cookie-only sessions with `HttpOnly`/`SameSite=Lax`/`Secure`-when-HTTPS set before `session_start`; logout clears the cookie and destroys the session; CSRF is a 256-bit per-session token compared with `hash_equals` and required on every admin write (login, logout, reset, accept, disconnect).
