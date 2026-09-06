# Demo account picker (login "Explore as …")

**Status:** design (pre-build) · **Classification:** Core (admin login + demo config)
· **Scope:** demo-mode only — zero effect on a real install.

## Problem

In demo mode the sign-in page pre-fills **one** published credential
(`NIMBUS_DEMO_EMAIL` / `NIMBUS_DEMO_PASSWORD`) so a visitor can log in with one
click. But a demo that wants to show its **roles** — a Restaurant demo with waiter
/ cook / manager / admin, a Food-store demo with staff tiers — can only expose one.
Visitors can't easily explore "what does a cook see vs a manager?" without knowing
the other addresses and retyping them.

## Goal

Let a demo publish a **list** of accounts, and let the visitor pick one from a
dropdown that fills the email + password. Generic: any Nimbus demo benefits; nothing
here is application-specific. When a demo publishes only one account (or just the
existing env pair), behaviour is exactly as today.

## Design

### Config — a list of demo accounts

Add `Config::demoAccounts(): list<array{label,email,password}>`:

- Reads an optional **`config/demo.php`** (`return ['accounts' => [ ['label' =>
  'Manager', 'email' => 'manager@site.demo', 'password' => '…'], … ]];`) — the same
  file-config pattern as `config/site.php` / `config/theme.php`.
- **Back-compat fallback:** if that file is absent or lists no valid account but
  `NIMBUS_DEMO_EMAIL` + `NIMBUS_DEMO_PASSWORD` are set, return that single pair as
  one account (label "Demo"). So every existing single-credential demo (e.g.
  Foodmart) keeps working untouched.
- Each entry is validated (non-empty string label/email/password); malformed
  entries are dropped. Never consulted unless `Config::demo()` is true (the caller
  gates), consistent with `demoEmail()`/`demoPassword()`.

`demoEmail()` / `demoPassword()` stay (the first account backs the existing
pre-fill), so nothing else in core needs to change.

### Login page

`AdminController::loginForm` passes `demoAccounts` (empty on a real install). In
`login.php`:

- **0 accounts** → unchanged (no pre-fill, no picker).
- **1 account** → unchanged (pre-filled fields, "credentials are filled in" note).
- **≥2 accounts** → render a `<select>` ("Explore as …", one option per label) above
  the fields; the fields start filled with the first account. A **nonce'd** inline
  script fills `#email` + `#password` when the selection changes. The accounts are
  emitted to the script as `json_encode(…, JSON_HEX_*)` — label/email/password are
  demo values but still escaped for the `<script>` sink.

No script is required to sign in — the fields are pre-filled server-side and the
form posts normally; the picker only *re-fills* them. (Progressive enhancement: with
JS off, the first account still works.)

## Reviews

### Platform review (three hats)

**🧑‍💼 Product** — Real, general demo-experience problem: showing a product's roles
is a core reason people build CMS demos. Benefits every demo, not one app. Drift
guard #4 (would I recommend this if Restaurant/Foodmart didn't exist?): yes — it's a
"demo mode" affordance, not restaurant-shaped. ✅

**🏗️ Architect** — Classification **Core**, but strictly demo-gated and additive: a
new optional config file + one config reader + a conditional UI branch. No new
capability, no API surface, no change to auth/session logic (the picker only fills
existing inputs; the real credential check is unchanged). Smallest reusable cut;
back-compat preserved via the env fallback. `config/demo.php` mirrors existing file
config. ✅

**👷 Principal engineer** — No change to the authentication path or session
handling. Malformed config entries dropped; empty list → today's behaviour. The
inline script is nonce'd (admin CSP is nonce-only for scripts) and its data is
`json_encode`-escaped. Mobile: the select uses the standard `nb-field` full-width
control (verify at 375px). Testable: `Config::demoAccounts()` parsing (file, env
fallback, malformed) + the login renders a picker only with ≥2 accounts and never on
a real install. **MCP surface check:** exempt — pure-presentation, demo-only login
affordance (ADR 0009 4b exempts pure presentation; there is no back-end capability
here). ✅

### Security review (Attacker / Defender / QA)

**🔴 Attacker** — (a) Can the picker leak or widen credentials on a real install?
(b) XSS via a crafted account label/email into the inline script? (c) Does it weaken
the login/auth path?

**⚪ Defender** —
- (a) **Demo-gated at the source:** `demoAccounts()` returns `[]` unless
  `Config::demo()` is true (caller-gated exactly like `demoEmail()`), and the data
  comes only from `config/demo.php` / the demo env — operator-authored, deployed
  with the site. On a production install there is no demo flag, so no accounts, no
  picker, no auto-fill. The passwords are *published demo* passwords by definition
  (same as the existing single pre-fill) — no secret is newly exposed. **Severity:
  n/a** (no real-install exposure).
- (b) The accounts are emitted into `<script>` via `json_encode` with
  `JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP`, so `</script>` / quotes in
  a label can't break out; any text rendered into HTML (option labels) goes through
  `View::e`. Source is operator config, not visitor input — defence-in-depth, not a
  reachable vector. **Severity: Low** (mitigated).
- (c) The auth path is untouched: the picker only sets the value of the existing
  email/password inputs client-side; the server still verifies the submitted
  credentials normally. CSRF token, throttling, and the demo change-password refusal
  are all unchanged. No new endpoint, no new state. **Nothing to block.**

**🟢 QA / permanence** — regression tests: (1) `Config::demoAccounts()` parses the
file, falls back to the env pair, and drops malformed rows; (2) the login page shows
the `<select>` only when demo + ≥2 accounts, and shows **no** demo affordance when
`NIMBUS_DEMO` is off even if `config/demo.php` exists; (3) the inline script carries
the page nonce. The existing `AuthRoutesTest` guards that the real sign-in still
works.

**Merge bar:** no Critical/High. Security-green to build.

## Definition of done

- `Config::demoAccounts()` + `config/demo.php` support, with the env single-account
  fallback (Foodmart unaffected).
- Login shows an "Explore as …" picker for ≥2 accounts; 0/1 unchanged; nothing on a
  real install.
- Nonce'd, `json_encode`-escaped fill script; progressive-enhancement safe.
- Tests: config parsing + login rendering (demo-on ≥2, demo-off none) green; PHPStan
  L6 + cs-fixer clean; verify at 375px.
- Restaurant demo ships `config/demo.php` (6 logins) and mounts it (deploy side).
