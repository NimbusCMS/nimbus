<div align="center">

# 🧹 NimbusCMS

**A modern, lightweight PHP CMS — collections, a themeable admin, and a little magic.**

*Point it at a fresh database, define your content, and go. No LAMP-era baggage.*

</div>

---

> ⚠️ **Status: in active development — not production-ready.**
> Content management, a media library, a read-only headless JSON API, and now basic server-rendered public pages work end to end today: define collections and fields; create, schedule and publish entries; upload media; read published content over the API; and render a collection's live entries and a single entry through a plain-PHP theme. **Public theming is a first slice** — a designated home page and richer theme capabilities are still to come. There is no upgrade path between versions, no password reset, and no release has been tagged. See [What works today](#what-works-today).

## Why NimbusCMS?

Most PHP CMSes are either enormous (WordPress) or abandoned. NimbusCMS is a small, modern, readable codebase you can actually understand end-to-end: PHP 8.2+, PDO, a clean layered architecture, its own schema via migrations, and a clean separation between content, admin and delivery. It's not trying to be WordPress — it's trying to be the CMS you'd be happy to fork.

## What works today

### ✅ Available now — built, integrated and covered by CI

- 🗂️ **Collections** — define content types and fields in the admin; entries stored as JSON, so adding a field never means an `ALTER TABLE`
- ✍️ **Entry CRUD** — create, edit, publish and delete, with server-side validation, inline errors and your input preserved on failure
- 🧩 **Nine field types** — text, textarea, number, boolean, select, date, email, URL, relation — behind a registry that plugins extend
- 🔗 **Relations** between collections, with referential cascade
- 📄 **Singletons** — single-entry collections for things like Site Settings
- 👤 **Roles** — per-collection manage permissions with an admin override
- 🔒 **Auth & hardening** — argon2id hashing, CSRF-guarded writes, session rotation on login, progressive login throttling, CSP + security headers on every response, configurable trusted proxies
- 🎨 **"Nimbus" admin theme** — night-sky admin skin, recolourable via CSS variables
- 🗓️ **Publishing lifecycle** — draft / published / scheduled / archived with cron-free scheduling; the API serves exactly the live set
- 🖼️ **Media library** — upload (content-validated, safe names), a library, and a `media` field the API expands to `{ url, alt, … }`
- 🔌 **Headless JSON API** — read-only `/api/v1`, bearer tokens, pagination, field values serialized through `toApi()`
- 🧩 **Plugins** — official [Markdown](https://github.com/NimbusCMS/plugin-markdown) plugin, a Composer-driven loader, and a read-only Plugins admin page

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

Today a plugin can register **field types**. Routes, events, permissions,
migrations and admin navigation are added one at a time, each alongside a
plugin that actually needs it.

### 🧪 Experimental — works, but the shape may still change

- 🔌 **Event hooks** — `entry.created` / `updated` / `saved` / `deleted`, dispatched after commit. Useful now; the event names and payloads are not yet frozen.
- 🧭 **Named routes & URL generation** — implemented and tested, but controllers still build paths as strings, so the names are not load-bearing yet.

### 🗺️ Roadmap — not built yet

- ✍️ Rich-text / Markdown editor · 📚 entry revisions · 📋 activity log
- 🏠 **Designated home page** · 🎨 richer theme capabilities (asset pipeline, template overrides) · 🔎 API filtering / sparse fieldsets

### 🚧 Not production-ready

No tagged release, no upgrade path between versions, no password reset, no backup tooling. Run it locally, fork it, read it — don't put a client's site on it yet.

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
```

## Architecture

A thin, layered kernel — easy to read, easy to extend. For the design
philosophy behind it, read [Core Principles](docs/architecture/CORE_PRINCIPLES.md).

```
public/index.php ─▶ Application (router)
                      ├─ Admin\*        the admin area (auth, dashboard, sections)
                      ├─ Api\*          read-only headless JSON API
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
- [x] Headless JSON API + tokens — read-only `/api/v1`, publishing-lifecycle aware, relations expanded
- [x] **Server-rendered public site (first slice)** — collection + entry pages via plain-PHP themes (`themes/starter`), live-set only
- [ ] Rich-text / Markdown editor
- [ ] RBAC + revisions + activity log
- [ ] Designated home page + richer theme capabilities

The full, continuously audited plan lives in [ROADMAP.md](ROADMAP.md), where
`[x]` means *verified by CI* — not merely *present in the repository*.
Architecture decisions are recorded in [docs/adr](docs/adr).

## Development

```bash
docker compose exec app composer check   # PHPStan level 6 + the full test suite
docker compose exec app composer test    # tests only
docker compose exec app tests/smoke.sh   # install from empty + CRUD over HTTP
```

## License

[MIT](LICENSE) © DanMat
