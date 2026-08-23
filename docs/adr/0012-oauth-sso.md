# 12. OAuth SSO — "Sign in with Google/GitHub" for the admin

- **Status:** Accepted (2026-08-22). Phase 1 shipped; Phases 2–4 deferred (each needs its own security review).
- **Date:** 2026-08-22
- **Related:** [ADR 0006](0006-non-human-authentication.md) (non-human auth / API
  tokens — this is the human-auth-extension sibling), `src/Auth/Auth.php` (session
  login), the invitation/accept seam (`/admin/accept`).
- **Drives:** letting an admin user sign in with an external identity provider
  (Google, GitHub) as an *alternative* to a password, and — later — accept an
  invitation or (opt-in) self-provision via a provider.

## Context

Password login is the only human auth today. Teams that already live in Google
Workspace or GitHub want provider sign-in: less password management, provider MFA
inherited, faster onboarding. It is a general CMS want, not app-specific.

Both review loops classified this **Core** (auth is foundational; SSO is a
sibling of the password login already in core) and **rejected making it a
plugin**: the plugin boundary deliberately excludes routes and auth (the highest
blast-radius surfaces), so a plugin would force opening those *speculatively* for
one consumer. It is a **phased initiative** with a security gate per phase.

## Decision

1. **Core, self-contained, opt-in, off by default.** A small OAuth subsystem in
   core. No provider configured ⇒ no buttons, no exercised routes, zero new
   surface, byte-identical behavior. **Password login always remains available**
   (the first admin and no-provider installs depend on it); SSO never replaces it.

2. **Authorization-Code flow + PKCE (S256) + `state`.** `state` is a single-use,
   session-bound CSPRNG value (login-CSRF/replay defense); PKCE defends an
   intercepted `code`. The callback binds to the **same provider** the start used.

3. **userinfo, not `id_token` — no JWT/JWKS dependency.** Tokens are fetched
   server-to-server from the provider's token endpoint over **TLS-verified** curl;
   identity comes from the userinfo endpoint (Google) / user + verified-emails API
   (GitHub). Provenance is the TLS-authenticated endpoint, so a local id_token
   signature check is unnecessary — this *avoids* the hard crypto problem rather
   than hand-rolling it, keeping core dependency-free. (`iss`/`aud` are checked
   where cheaply available.)

4. **Identity is keyed by the immutable provider subject**, stored in a dedicated
   `nb_oauth_identities(user_id, provider, provider_user_id, UNIQUE(provider,
   provider_user_id))` — never by email (emails change/reassign), never as columns
   on `nb_users` (one row per link supports multiple providers). No access/refresh
   tokens are stored (identity-at-login only).

5. **Linking policy, staged by risk:**
   - **Phase 1:** *explicit link* from a logged-in user's settings ("Connect
     Google") + *sign-in* for an already-linked identity. An **unknown** identity
     is **rejected** ("ask an admin to invite you") — no auto-provision, and
     **email is display-only, never a matching key**. This removes the entire
     verified-email takeover class from Phase 1.
   - **Phase 2:** invitation-accept via a provider (verified provider email ==
     the invited email) on `/admin/accept`.
   - **Phase 3 (opt-in):** auto-link an unknown identity to an existing user by a
     provider-**verified** email (GitHub: verified primary only). Off by default.
   - **Phase 4 (opt-in):** open self-service signup behind an allow-list policy
     (e.g. a domain) → auto-create at a configured **low** role, never admin.
   Each phase gets its own security review.

6. **`Auth::login(int $userId)`** — a password-less session login that
   `session_regenerate_id(true)` for session-fixation parity with `attempt()`.

7. **Config:** per-provider `OAUTH_{GOOGLE,GITHUB}_CLIENT_ID` / `_SECRET` in the
   environment (secret is a secret — never front-channel, logged, or returned).
   The redirect URI is derived from `APP_URL` (Host-poisoning-safe).

## Consequences

- A new public-ish `OAuthProvider` interface (2 built-in adapters) — kept minimal
  and marked **not frozen** in COMPATIBILITY until a third provider proves it.
- An auth path to keep correct forever — mitigated by: off-by-default, phased,
  a fakeable provider for hermetic tests, and the per-phase security gate.
- Reversible while unused (off by default); the `nb_oauth_identities` table and
  the interface are the only durable surfaces.

## Alternatives rejected

- **SSO as a plugin** — would open arbitrary-route + auth-hook plugin surfaces
  speculatively; auth is too high-blast-radius to delegate for one consumer.
- **Local `id_token` JWT validation** — needs a JWT/JWKS lib for no benefit over
  the TLS-provenance userinfo call in a confidential-client server flow.
- **Email as the identity key** — emails change and reassign; subject-keying is
  the stable, takeover-resistant choice.
