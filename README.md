<div align="center">

# 🧹 NimbusCMS

**A modern, lightweight PHP CMS — collections, a themeable admin, and a little magic.**

*Point it at a fresh database, define your content, and go. No LAMP-era baggage.*

</div>

---

> ⚠️ **Status: alpha (`0.x`) — feature-rich and in active development, approaching a first `0.1` release. Not production-ready.**
> A lot works end to end today: define collections and fields; create, schedule and publish entries; upload media; compose **capability-based roles** for people *and* machines; drive the whole CMS over a scoped **headless JSON API** (read + write, ETag/If-Match, OpenAPI) **and over MCP** (agents are first-class operators); and render a public site through plain-PHP themes. The **admin is themeable** — four built-in themes incl. dark mode, plus a per-user picker — and **mobile-native** (an off-canvas nav, responsive tables and forms). Still alpha, honestly: **no release is tagged** and there is **no upgrade path** between versions yet. Public theming and richer capabilities are still growing. See [What works today](#what-works-today) and [ROADMAP.md](ROADMAP.md).

## Why NimbusCMS?

Most PHP CMSes are either enormous (WordPress) or abandoned. NimbusCMS is a small, modern, readable codebase you can actually understand end-to-end: PHP 8.2+, PDO, a clean layered architecture, its own schema via migrations, and a clean separation between content, admin and delivery. It's not trying to be WordPress — it's trying to be the CMS you'd be happy to fork.

## ⚡ Fast by default

A public page is server-rendered HTML + one ~1 KB stylesheet — **no client-side
framework, no render-blocking JavaScript, no web fonts, no third-party requests.**
A real content page scores **100 / 100 on Lighthouse** (mobile) with **perfect
Core Web Vitals** (FCP/LCP 0.8 s, TBT 0 ms, CLS 0) at **~5.6 KB over 2 requests**,
out of the box — before the optional page cache. Measured and reproducible:
[docs/PERFORMANCE.md](docs/PERFORMANCE.md).

## What works today

### ✅ Available now — built, integrated and covered by CI

- 🗂️ **Collections** — define content types and fields in the admin; entries stored as JSON, so adding a field never means an `ALTER TABLE`
- ✍️ **Entry CRUD** — create, edit, publish and delete, with server-side validation, inline errors and your input preserved on failure
- 🧩 **Nine field types** — text, textarea, number, boolean, select, date, email, URL, relation — behind a registry that plugins extend
- 🔗 **Relations** between collections, with referential cascade
- 📄 **Singletons** — single-entry collections for things like Site Settings
- 👤 **Roles & capabilities** — admin-composed roles from one `resource:action` capability vocabulary shared by users **and** API tokens; users hold the union of their roles, tokens carry scopes and/or a live-bound role, deny-by-default with subset-only granting ([docs/ROLES.md](docs/ROLES.md))
- 🔒 **Auth & hardening** — argon2id hashing, CSRF-guarded writes, session rotation on login, progressive login throttling, **self-service password reset** and **email invitations** (invite a user → they set their own password; one-time hashed tokens, no account enumeration), CSP + security headers on every response, configurable trusted proxies
- ✉️ **Pluggable mail** — a small `Mailer` behind one interface: `log` (default — writes to a file, zero config), `native` (PHP `mail()`), or `api` (a transactional provider's HTTPS API — one key, no SMTP). Used by password reset today
- 🔑 **SSO — "Sign in with Google / GitHub"** (optional, **off by default**) — Authorization-Code + PKCE, dependency-free (no JWT library); a user links a provider from Settings and then signs in without a password. Password sign-in always stays available; no account is ever auto-created (a person still has to be invited). See [ADR 0012](docs/adr/0012-oauth-sso.md)
- 🎨 **Themeable, mobile-native admin** — six built-in themes (**Nimbus** night-sky, **Nocturne** dark, **Daybreak**, **Grimoire**, **Auto** match-device, **Owl** high-contrast) with a per-user picker, all token-driven; a phone-native shell (off-canvas nav drawer, responsive tables/forms), WCAG-AA, one inlined vanilla-CSS file — no framework, no build ([docs/design/admin-experience.md](docs/design/admin-experience.md))
- 🗓️ **Publishing lifecycle** — draft / published / scheduled / archived with cron-free scheduling; the API serves exactly the live set
- 🖼️ **Media library** — upload (content-validated, safe names), a library, and a `media` field the API expands to `{ url, alt, … }`
- 🔌 **Headless JSON API** — read + write `/api/v1`, scoped bearer tokens (expiry/pause/revoke), ETag/If-Match concurrency, rate limiting + CORS, `toApi()` serialization; self-describing via generated **OpenAPI** (`GET /api/v1/openapi.json`)
- ⚙️ **Site settings** — admin-editable site configuration (site title, home page, default meta description) in a small typed store, with `.env`/`config/*.php` as the shipped default the database overrides; gated on `settings:write` and editable over the API/MCP too. Deploy/env config stays in files
- 🤖 **MCP-native** — an agent with a scoped token operates the whole CMS over the [Model Context Protocol](https://modelcontextprotocol.io) (HTTP `POST /api/v1/mcp` **and** stdio `nimbus mcp`): content, schema, media, users, tokens and settings, through the same scope-checked, audited services the admin uses — not a bolt-on. See [docs/MCP.md](docs/MCP.md)
- 🧩 **Plugins** — official [Markdown](https://github.com/NimbusCMS/plugin-markdown), [SEO](https://github.com/NimbusCMS/plugin-seo) and [Analytics](https://github.com/NimbusCMS/plugin-analytics) plugins, a Composer-driven loader, and a read-only Plugins admin page

### 🔌 Plugins

Plugins are ordinary Composer packages. Install one and it works:

```bash
composer require nimbuscms/markdown
```

Discovery is Composer's `installed.json` — there is no upload step and no
in-admin installer, because downloading and executing arbitrary code needs
signing, compatibility and rollback policies designed first.

Disable a plugin in `config/plugins.php` and your content is safe: entries
using its field type stay in the database untouched, the admin shows them
read-only and names the missing provider, and saves are refused until it is
back. See [ADR 0001](docs/adr/0001-plugin-contract.md) for the contract and
[plugin-markdown](https://github.com/NimbusCMS/plugin-markdown) for a worked
example.

**System → Plugins** in the admin lists what Composer installed and the state
the loader made of each package — enabled, disabled or failed, with the reason.
It is a diagnostic view, not an installer: plugins are managed with
`composer require`/`remove` and enabled or disabled in `config/plugins.php`.

Today a plugin can register **field types**, **document-head contributions**
(structured data / meta for public pages, see [ADR 0004](docs/adr/0004-plugin-head-contributions.md)),
**event listeners** (including `request.handled`), **its own migrations and
tables** ([ADR 0005](docs/adr/0005-plugin-owned-storage.md) — own tables only),
and **admin pages** (with a nav entry). Each of these was added alongside an
official plugin that actually needed it — Markdown (field types), SEO (head), and
Analytics (events, migrations, storage, admin pages). Arbitrary routes, custom
permissions, and access to *core* tables are deliberately still not exposed. The
authoritative, evidence-backed capability matrix lives in
[`references/capability-evidence.md`](.claude/skills/nimbus-review-loop/references/capability-evidence.md).

### 🧪 Experimental — works, but the shape may still change

- 🔌 **Event hooks** — `entry.created` / `updated` / `saved` / `deleted`, dispatched after commit. Useful now; the event names and payloads are not yet frozen.
- 🧭 **Named routes & URL generation** — implemented and tested, but controllers still build paths as strings, so the names are not load-bearing yet.

### 🗺️ Roadmap — not built yet

- ✍️ Rich-text editor · 📚 entry revisions · 📋 activity log
- 🎨 **Plugin-provided / multiple installable themes** (a single starter theme, home page, template overrides, partials and an asset pipeline already ship) · 🔎 API filtering / sparse fieldsets

### 🚧 Not production-ready

No tagged release, no upgrade path between versions, no backup tooling. Run it locally, fork it, read it — don't put a client's site on it yet.

## Quick start

```bash
git clone https://github.com/NimbusCMS/nimbus.git && cd nimbus
cp .env.example .env
docker compose up -d --build          # app :8080 · adminer :8081 · mysql :3307
docker compose exec app php bin/nimbus install
```

The installer prints the account it created. Open **http://localhost:8080/admin** and sign in.

> The convenience credentials exist **only** when `APP_ENV=local`, which the
> shipped `docker-compose.yml` sets for local development. In any other
> environment the installer refuses to seed defaults or weak passwords — you
> must pass `--email=` and a strong `--password=`:
>
> ```bash
> php bin/nimbus install --email=you@example.com --password='a long unique passphrase'
> ```

### CLI

```bash
php bin/nimbus migrate                                 # run pending migrations
php bin/nimbus install --email=you@site.com --password='a long unique passphrase'
php bin/nimbus create-user --email=ed@site.com --role=editor
php bin/nimbus mail:test you@site.com                  # verify the mail transport
```

## Architecture

A thin, layered kernel — easy to read, easy to extend. For the design
philosophy behind it, read [Core Principles](docs/architecture/CORE_PRINCIPLES.md).

```
public/index.php ─▶ Application (router)
                      ├─ Admin\*        the admin area (auth, dashboard, sections)
                      ├─ Api\*          headless JSON API (read + write)
                      ├─ Site\*         server-rendered public site  (collection + entry pages)
                      ├─ Content\*      collections, fields, entries
                      ├─ Media\*        uploads + library
                      ├─ Auth\*         argon2id sessions
                      ├─ Database\*     PDO facade + migrations
                      └─ View\*         template renderer + themes/starter (public), admin skin
```

Content lives in JSON columns (`nb_entries.data`) keyed by collection, so adding a
field never means an `ALTER TABLE`.

## Roadmap

- [x] Foundation: Docker stack, migrations, auth, themed admin shell
- [x] Collections & fields + entry CRUD — field-type registry, relations, singletons, per-collection role permissions, post-commit events
- [x] Hardened HTTP core — `Response` object, middleware-gated routes, CSP + security headers, login throttling, trusted proxies
- [x] Test & analysis baseline — unit, integration and HTTP-functional suites, PHPStan level 6, install + CRUD smoke test, all on CI
- [x] Plugin system — `Plugin` + `PluginContext` + Composer-driven loader, proven by [plugin-markdown](https://github.com/NimbusCMS/plugin-markdown)
- [x] Media library — upload, library, and a `media` field served by the API
- [x] Headless JSON API + tokens — read + write `/api/v1`, scoped tokens, ETag/If-Match, rate limiting + CORS, publishing-lifecycle aware, relations expanded
- [x] **Server-rendered public site** — home page (`config/site.php`) + collection + entry pages via plain-PHP themes (`themes/starter`), live-set only
- [x] **Theme capabilities** — partials, per-collection template specialization, themed 404, static assets (`/theme/assets`), navigation menus, reusable blocks
- [x] **Public-site polish** — config-driven URL redirects (applied before routing) and opt-in page caching (`PAGE_CACHE_TTL`, flushed on every content write)
- [x] **SEO foundations** — per-page meta + canonical + Open Graph, `sitemap.xml`, `robots.txt`
- [x] **Roles & capabilities** — one `resource:action` vocabulary for users **and** tokens; admin-composed roles (union per user, live-bound to tokens), deny-by-default, subset-only granting across admin + CLI + MCP ([docs/ROLES.md](docs/ROLES.md))
- [x] **MCP control surface** — the full CMS over MCP (HTTP + stdio): content, schema, media, users, tokens, settings — scope-checked, non-enumerating, audited, through the same services the admin uses ([docs/MCP.md](docs/MCP.md))
- [x] **Site settings store** — a typed, DB-backed store for admin-editable site config (title, home, description), `.env`/`config/*.php` the default it overrides; `settings:write` capability, admin form + MCP tools, deploy/env config kept in files
- [x] **Themeable, mobile-native admin** — four token-driven themes + per-user picker, an off-canvas mobile nav and responsive tables/forms, one inlined vanilla-CSS file ([docs/design/admin-experience.md](docs/design/admin-experience.md))
- [ ] Rich-text / Markdown editor
- [ ] Revisions + activity log
- [ ] `plugin-seo`: JSON-LD, social-card images, RSS/Atom, meta-editing UI

The full, continuously audited plan lives in [ROADMAP.md](ROADMAP.md), where
`[x]` means *verified by CI* — not merely *present in the repository*.
Architecture decisions are recorded in [docs/adr](docs/adr).

## Development

```bash
docker compose exec app composer check   # dependency audit + PHPStan level 7 + the full test suite
docker compose exec app composer test    # tests only
docker compose exec app composer audit   # dependency vulnerability scan (also runs in CI)
docker compose exec app tests/smoke.sh   # install from empty + CRUD over HTTP
```

`composer audit` fails on a known advisory in the committed `composer.lock`. If a
dev-only dependency is ever flagged with no fix available, scope the exception at
the audit step (severity/advisory id) rather than dropping the check.

## Contributing & community

- **[Contributing guide](CONTRIBUTING.md)** — setup, the quality gate, conventions, PR flow.
- **[Security policy](SECURITY.md)** — report a vulnerability privately (never a public issue).
- **[Code of Conduct](CODE_OF_CONDUCT.md)** · **[Changelog](CHANGELOG.md)**
- Read **[docs/CHARTER.md](docs/CHARTER.md)** before proposing a change — it's the gate every change is measured against.

## License

[MIT](LICENSE) © DanMat
