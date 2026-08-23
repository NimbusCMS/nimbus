# Support, Settings & Mail — audit findings (2026-08)

**Domain summary.** This domain is in strong shape: the settings store's registry
allow-list holds at both surfaces (admin + MCP), secrets (DB pass, MAIL_API_KEY,
OAuth secrets) never reach a log/URL/error path, the mailer family fails closed on
CRLF and enforces https+TLS, and file↔DB precedence (`array_key_exists`) is correct
where it matters. No Critical/High. The real findings cluster at operational edges:
a mail-transport typo that reports *false delivery success*, control characters
slipping through the `site.title` validator into mail subjects, and `emitBestEffort`
silently dropping later listeners (audit records included) when an earlier one throws.

---

### SUP-1 · A `MAIL_TRANSPORT` typo silently reroutes all mail to the log — and reports delivery success
- **Priority:** P2
- **Type:** error-handling
- **Severity (if security):** —
- **Where:** `src/Mail/MailerFactory.php:18-23` (+ `src/Auth/InvitationService.php:47-53`, `src/Auth/PasswordResetService.php:57-63`)
- **What:** any unrecognized `MAIL_TRANSPORT` value falls through `match`'s `default` to `LogMailer`, whose `send()` *succeeds* — so the invite flow returns `true` ("Invitation sent") and resets "send" into a file nobody reads, with no warning anywhere.
- **Evidence:** set `MAIL_TRANSPORT=nativ` (one-letter typo). `MailerFactory::fromConfig()` → `LogMailer`. Admin invites a user → `InvitationService::sendInvite()` returns `true` → the admin sees the success flash; the invitee never receives mail. Password resets likewise never leave the box. Nothing is logged at boot or send time — the docblock's "never silently drop mail … with a trace" holds only if someone tails `storage/mail/mail.log`. (The fallback itself is a recorded design decision; the un-considered consequence is the *false success* signal to the admin.)
- **Fix:** keep the fail-safe fallback, but make it loud: in `MailerFactory`, `error_log` a clear warning when the configured transport is not `log|native|api` (one line, once per request that builds a mailer). Optionally (small, high-value operator win): a `nimbus mail:test <address>` CLI command that sends through the configured transport and prints the outcome — the only way an operator can verify `api`/`native` config today is to lock themselves out and try a reset.
- **Effort:** S

### SUP-2 · `site.title` accepts control characters — a stored CRLF title silently kills all reset/invite mail
- **Priority:** P2
- **Type:** security
- **Severity (if security):** Low
- **Where:** `src/Settings/SettingsRegistry.php:44-51` (validator) → `src/Auth/PasswordResetService.php:58`, `src/Auth/InvitationService.php:48` (subject) → `src/Mail/NativeMailer.php:41-47`
- **What:** the title validator checks only non-empty + ≤80 chars; an embedded `\r\n` (trivially storable via MCP `set_settings` — JSON strings carry raw CRLF; `trim()` strips only the ends) survives to the mail *subject*, where `NativeMailer::assertHeaderSafe` throws — so every password-reset and invite mail fails, and the reset flow swallows the exception by design.
- **Evidence:** MCP call `set_settings {"settings":{"site.title":"Nimbus\r\nX"}}` → passes validation (non-empty, 8 chars), stored. Next `/admin/forgot` for any user: subject `"Reset your Nimbus\r\nX password"` → `MailerException("Illegal newline in mail subject.")` → caught and only `error_log`'d (anti-enumeration contract) → **no reset mail is ever delivered again**, with the identical "if that account exists…" page showing. Fail-closed (no header injection — the mailer guard works), but a `settings:write` holder gets a silent, persistent DoS of the account-recovery flow; on the `api` transport the CRLF is forwarded to the provider inside JSON (inert for sane providers, but our boundary shouldn't emit it). Same class as the ledger's 2026-08-22 watch: "making a previously-trusted config value user-editable requires auditing every downstream consumer" — the mail subject is the consumer the title audit missed (it checked only HTML/JSON sinks).
- **Fix:** in the `site.title` validator, reject control characters — `preg_match('/[\x00-\x1F\x7F]/u', $value)` → "The title can't contain control characters." (Consider the same for any future `text`-type setting; `site.description` needs nothing — its only sinks are HTML-escaped meta tags where newlines are inert.)
- **Effort:** S

### SUP-3 · `emitBestEffort` isolates the *dispatch*, not each *listener* — one throwing subscriber suppresses every later one, audit records included
- **✅ RESOLVED** (Slice E, 2026-08-23) — per-listener try/catch in `emitBestEffort` (logs the provider, continues); `dispatch()` still propagates for entry events. Test added (closes the SUP-9(3) gap).
- **Priority:** P2
- **Type:** error-handling
- **Severity (if security):** Low
- **Where:** `src/Support/EventDispatcher.php:58-68` (`emitBestEffort` wrapping `dispatch`)
- **What:** the `try` wraps the whole listener loop, so the first listener that throws aborts the loop — listeners registered after it (e.g. the api-advanced audit plugin recording `API_ACCESS_DENIED` / `API_MANAGEMENT_WRITTEN`) never fire for that event, and the only trace is one `error_log` line. The docblock promises "a listener that throws is caught and logged" — per-listener isolation — but the implementation is whole-dispatch.
- **Evidence:** two plugins subscribe to `API_MANAGEMENT_WRITTEN` (plugin A registered first, plugin B is the audit log). Plugin A's listener throws on a payload edge (e.g. a `TypeError` on an unexpected key). `dispatch()` propagates out of the loop at listener A → the `catch` logs it → **plugin B records nothing** for that management write. An agent reshaping the CMS leaves a hole in the who-did-what trail exactly when another plugin is buggy — and a hostile-but-subtle plugin could throw selectively to blind the audit log (Low: a hostile in-process plugin can do worse, per ADR 0001; the realistic case is a merely buggy one).
- **Fix:** in `emitBestEffort`, iterate the listeners and wrap **each call** in its own `try/catch` (log per failure, continue). Keep `dispatch()` propagating as documented for post-commit entry events. Add the missing test: two listeners, first throws, assert the second still ran (there is no `emitBestEffort` test at all today — `tests/Unit/EventDispatcherTest.php` covers order/forgetProvider only).
- **Effort:** S

### SUP-4 · Admin settings save discards the field-level error — the operator gets a generic flash while MCP gets per-key messages
- **Priority:** P3
- **Type:** product-gap
- **Where:** `src/Admin/SettingsController.php:186-190` (+ `src/View/themes/nimbus/templates/settings/index.php:25-27`)
- **What:** `saveSite()` computes `$setting->validate($value)`'s message ("Keep the description under 500 characters.", "No collection has the handle …") and throws it away, redirecting to a fixed `?flash=site-error` ("Some settings couldn't be saved — please check the values"); the MCP `set_settings` returns the exact key + message. The human operator is the worse-served surface — the opposite of the structured-validation-errors slice's direction for entries.
- **Evidence:** POST a 501-char description + a valid home in one save → redirect with the generic banner; nothing says *which* field failed or why, and the valid home is also not saved (all-or-nothing without saying so).
- **Fix:** carry the failing key + message through the redirect the same way the entries form does (session flash or a fixed-key map keyed by setting), and render it beside the field. Keep the all-or-nothing write (correct), just say what failed.
- **Effort:** S

### SUP-5 · `Env` parser keeps inline `#` comments in unquoted values
- **Priority:** P3
- **Type:** correctness
- **Where:** `src/Support/Env.php:19-34`
- **What:** a line `MAIL_API_KEY=re_abc123 # production key` yields the value `re_abc123 # production key` — every mainstream dotenv implementation strips an unquoted trailing comment, so operators reasonably write them; here the comment silently corrupts the secret. (`export KEY=…` lines are likewise skipped as not-containing-`=`-first — minor, same fix site.)
- **Evidence:** `.env` containing `MAIL_API_KEY=re_abc123 # prod` → `Config::mailApiKey()` returns the whole string → `ApiMailer` sends `Authorization: Bearer re_abc123 # prod` → provider 401 → "Mail provider returned HTTP 401." with no hint the key was mangled.
- **Fix:** in `Env::load()`, for unquoted values strip from the first ` #` (space-hash) onward before trimming; optionally accept a leading `export `. Add the first `EnvTest` while there (see SUP-9).
- **Effort:** S

### SUP-6 · `NativeMailer` sends non-ASCII subjects raw — a Unicode site title mojibakes the subject line
- **Priority:** P3
- **Type:** correctness
- **Where:** `src/Mail/NativeMailer.php:32-37` (+ subject producers in `PasswordResetService`/`InvitationService`)
- **What:** `mail()` gets the subject verbatim; RFC 5322 headers are ASCII, so a title like `Café Süd` (perfectly valid per the settings validator, and now admin-editable) produces an un-encoded UTF-8 subject that many MTAs/clients render as `CafÃ© SÃ¼d`.
- **Evidence:** set `site.title` to `Café` → request a reset with `MAIL_TRANSPORT=native` → subject header bytes are raw UTF-8 with no `=?UTF-8?B?…?=` word; body is fine (its Content-Type declares UTF-8), the subject is not.
- **Fix:** in `NativeMailer::send()`, when the subject is not pure ASCII, encode it: `'=?UTF-8?B?' . base64_encode($subject) . '?='` (after the CRLF guard). The `api` transport is unaffected (JSON).
- **Effort:** S

### SUP-7 · Multi-key settings save is not atomic — a mid-loop DB failure applies some keys and not others
- **Priority:** P3
- **Type:** error-handling
- **Where:** `src/Admin/SettingsController.php:194-197`, `src/Mcp/SettingsToolset.php:120-127`
- **What:** both surfaces correctly validate *all* submitted values before writing any, but then write in a plain loop — a `PDOException` on the second `set()` (connection drop, lock timeout) leaves the first key committed, contradicting the validate-all-then-write intent; the MCP caller additionally gets a generic error with no statement of partial application.
- **Evidence:** `set_settings {"settings":{"site.title":"New","site.description":"…"}}` with the DB failing between the two upserts → `site.title` persisted, `site.description` not, error reported as total failure.
- **Fix:** wrap the write loop in a transaction (`Connection` already has the primitives the services use); the per-key audit emits can stay post-commit by collecting keys first.
- **Effort:** S

### SUP-8 · `LogMailer` writes account-takeover links with default (umask) file permissions
- **Priority:** P3
- **Type:** security
- **Severity (if security):** Low
- **Where:** `src/Mail/LogMailer.php:23-37`
- **What:** `storage/mail/mail.log` holds live reset/invite links; the file is created by `file_put_contents` with default mode (typically 0644 after umask). The **existing control** is the directory: `mkdir(…, 0770)` (→ 0750 after a 022 umask) denies other users traversal, so there is no direct exploit under defaults — this is a depth gap for deploys where the directory pre-exists with looser modes (created by a container image, a permissive umask, or a checkout).
- **Evidence:** on a host with umask 000 or a pre-existing world-traversable `storage/mail/`, any local user reads unexpired single-use reset links and takes over accounts. Not reachable under default creation — rated Low accordingly (assumption that would raise it: shared hosts where the dir is provisioned world-readable).
- **Fix:** `chmod($this->path, 0600)` after the first successful write (ignore failure); one line of depth matching the file's contents. Rotation/size-capping can wait for a real operator need.
- **Effort:** S

### SUP-9 · Test gaps: the load-bearing `array_key_exists` precedence, `Env` entirely, `emitBestEffort` entirely
- **Priority:** P3
- **Type:** test-gap
- **Where:** `tests/Http/SettingsSiteTest.php` (no clear-to-empty case), no `tests/Unit/EnvTest.php`, `tests/Unit/EventDispatcherTest.php` (no `emitBestEffort` case)
- **What:** three documented, load-bearing behaviours have no regression test: (1) a **cleared** setting (stored `''`) must override a non-empty file default — `Settings::get`'s `array_key_exists`-not-`??` semantic; a refactor to `?? $default` would silently resurrect the file home after an admin chose "no home page"; (2) `Env` — real-env-beats-.env precedence, quote stripping, comment/blank skipping — is untested; (3) `emitBestEffort`'s isolation contract is untested (see SUP-3's fix).
- **Evidence:** change `Settings::get` line 37 to `return $this->stored[$key] ?? ($this->registry->find($key)?->default ?? '');` — the full suite stays green while "clear the home page" now renders the config-file home at `/`.
- **Fix:** (1) an HTTP test: configure a file home, save `site.home=''` via the admin form, assert `/` shows the placeholder; (2) a small `EnvTest` (temp file: precedence, quotes, comments — extend with SUP-5's inline-comment case); (3) the two-listener throw test from SUP-3.
- **Effort:** S

### SUP-10 · `Settings`/`SettingsRegistry` are hand-assembled at six call sites instead of composed once
- **Priority:** P3
- **Type:** architecture
- **Where:** `src/Application.php:323-327`, `src/Admin/Controller.php:134-137`, `src/Admin/SettingsController.php:51-52`, `src/Admin/PasswordResetController.php:48`, `src/Admin/UsersController.php:48`, `src/Api/ApiController.php:75-76`
- **What:** six sites each build their own `new Settings(new SettingsRepository($db), new SettingsRegistry(new CollectionRepository($db)))`; each instance carries its own memo, so one admin request can run the same `SELECT key, value FROM nb_settings` two-plus times (base-controller shell title + the handling controller), and each `SettingsRegistry` construction re-`require`s `config/site.php` twice. Cost is small (tiny table, opcache), but it breaks the codebase's own "composed once and shared" pattern (`EventDispatcher`, `PageCache`, the registries) and each new consumer adds another copy to keep in sync.
- **Evidence:** load `/admin/settings` as an admin: `Admin\Controller::siteTitle()` queries `nb_settings`, then `SettingsController::index()`'s own instance queries it again — an identical query per request that a shared instance would memoize away.
- **Fix:** compose one `Settings` (and its registry) in `Application::__construct` beside the dispatcher/page cache, and pass it to the controllers that need it (they already receive constructor collaborators). No behaviour change; delete five construction sites.
- **Effort:** S

---

## What's solid

The registry allow-list is genuinely the boundary it claims to be — both write
surfaces iterate/lookup the registry (never the request keys), proven by tests on
each; `settings:write` is wildcard-immune on both; every MCP write is audited; all
SQL is bound-param with the MySQL8 row-alias upsert (no reused placeholder).
Secrets discipline held everywhere I traced: `MAIL_API_KEY` reaches only the curl
Authorization header, `ApiMailer` errors report transport/status only, OAuth
secrets never leave `Config::oauthProviders()`'s consumers, and `error_log` calls
carry messages, never tokens or links. `NativeMailer`'s CRLF guard and recipient
validation, `ApiMailer`'s https-only + verified TLS, `Config::normalizeRedirects`'s
drop-don't-trust parsing, `PageCache`'s hashed keys + temp-file/rename writes, and
`bin/nimbus prune`'s per-task isolation of `MaintenanceRegistry` callables are all
correct. Env precedence (real env beats `.env`) is right; the file↔DB settings
boundary matches the recorded principle exactly.
