<div align="center">

# 🧹 NimbusCMS

**A modern, lightweight PHP CMS — collections, a themeable admin, and a little magic.**

*Point it at a fresh database, define your content, and go. No LAMP-era baggage.*

</div>

---

> ⚠️ **Status: in active development — not production-ready.**
> Content management works end to end today: define collections and fields, then create, edit and publish entries. Media, the headless API and the public site are not built yet. There is no upgrade path between versions, no password reset, and no release has been tagged. See [What works today](#what-works-today).

## Why NimbusCMS?

Most PHP CMSes are either enormous (WordPress) or abandoned. NimbusCMS is a small, modern, readable codebase you can actually understand end-to-end: PHP 8.2+, PDO, a clean layered architecture, its own schema via migrations, and a headless-first mindset. It's not trying to be WordPress — it's trying to be the CMS you'd be happy to fork.

## What works today

### ✅ Available now — built, integrated and covered by CI

- 🗂️ **Collections** — define content types and fields in the admin; entries stored as JSON, so adding a field never means an `ALTER TABLE`
- ✍️ **Entry CRUD** — create, edit, publish and delete, with server-side validation, inline errors and your input preserved on failure
- 🧩 **Nine field types** — text, textarea, number, boolean, select, date, email, URL, relation — behind a registry that plugins will extend
- 🔗 **Relations** between collections, with referential cascade
- 📄 **Singletons** — single-entry collections for things like Site Settings
- 👤 **Roles** — per-collection manage permissions with an admin override
- 🔒 **Auth & hardening** — argon2id hashing, CSRF-guarded writes, session rotation on login, progressive login throttling, CSP + security headers on every response, configurable trusted proxies
- 🎨 **"Nimbus" admin theme** — night-sky admin skin, recolourable via CSS variables

### 🧪 Experimental — works, but the shape may still change

- 🔌 **Event hooks** — `entry.created` / `updated` / `saved` / `deleted`, dispatched after commit. Useful now; the event names and payloads are not yet frozen.
- 🧭 **Named routes & URL generation** — implemented and tested, but controllers still build paths as strings, so the names are not load-bearing yet.

### 🗺️ Roadmap — not built yet

- 🖼️ Media library · 🔌 headless JSON API + tokens · ✍️ rich text / Markdown editor
- 📚 Entry revisions · 📋 activity log · 🌐 themeable public site · 🧱 plugin system

### 🚧 Not production-ready

No tagged release, no upgrade path between versions, no password reset, no backup tooling. Run it locally, fork it, read it — don't put a client's site on it yet.

## Quick start

```bash
git clone https://github.com/DanMat/NimbusCMS.git && cd NimbusCMS
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

A thin, layered kernel — easy to read, easy to extend:

```
public/index.php ─▶ Application (router)
                      ├─ Admin\*        the admin area (auth, dashboard, sections)
                      ├─ Api\*          headless JSON API            (roadmap)
                      ├─ Site\*         public front-end             (roadmap)
                      ├─ Content\*      collections, fields, entries
                      ├─ Media\*        uploads + library            (roadmap)
                      ├─ Auth\*         argon2id sessions
                      ├─ Database\*     PDO facade + migrations
                      └─ View\*         theme templates + themes/nimbus
```

Content lives in JSON columns (`nb_entries.data`) keyed by collection, so adding a
field never means an `ALTER TABLE`.

## Roadmap

- [x] Foundation: Docker stack, migrations, auth, themed admin shell
- [x] Collections & fields + entry CRUD — field-type registry, relations, singletons, per-collection role permissions, post-commit events
- [x] Hardened HTTP core — `Response` object, middleware-gated routes, CSP + security headers, login throttling, trusted proxies
- [x] Test & analysis baseline — unit, integration and HTTP-functional suites, PHPStan level 6, install + CRUD smoke test, all on CI
- [ ] Plugin system (`Plugin` interface + `PluginContext`)
- [ ] Media library
- [ ] Headless JSON API + tokens
- [ ] Rich-text / Markdown editor
- [ ] RBAC + revisions + activity log
- [ ] Themeable public site

The full, continuously audited plan lives in [ROADMAP.md](ROADMAP.md), where
`[x]` means *verified by CI* — not merely *present in the repository*.

## Development

```bash
docker compose exec app composer check   # PHPStan level 6 + the full test suite
docker compose exec app composer test    # tests only
docker compose exec app tests/smoke.sh   # install from empty + CRUD over HTTP
```

## License

[MIT](LICENSE) © DanMat
