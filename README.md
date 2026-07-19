<div align="center">

# 🧹 NimbusCMS

**A modern, lightweight PHP CMS — collections, media, a headless API, and a themeable admin, with a little magic.**

*Point it at a fresh database, define your content, and go. No LAMP-era baggage.*

</div>

---

> ⚠️ **Status: in active development.** The foundation (modern Docker stack, migrations, argon2id auth, themed admin shell) is in place; content, media, API and the public site are landing next — see the [roadmap](#roadmap).

## Why NimbusCMS?

Most PHP CMSes are either enormous (WordPress) or abandoned. NimbusCMS is a small, modern, readable codebase you can actually understand end-to-end: PHP 8.2+, PDO, a clean layered architecture, its own schema via migrations, and a headless-first mindset. It's not trying to be WordPress — it's trying to be the CMS you'd be happy to fork.

## Features

- 🗂️ **Collections** — define content types & fields; entries stored as JSON, so the schema never fights you
- 🖼️ **Media library** — drag-drop uploads, thumbnails, alt text, reusable picker _(roadmap)_
- 🔌 **Headless JSON API** — read your content from anywhere, with API tokens _(roadmap)_
- ✍️ **Rich text / Markdown** editor for body content _(roadmap)_
- 👥 **Roles & permissions**, entry **revisions**, and an **activity log** _(roadmap)_
- 🎨 **Theme management** — the "Nimbus" night-sky theme, light/dark presets, brand colour, per-user
- 🌐 **Themeable public site** for pages & blogs _(roadmap)_
- 🔒 **Modern auth** — argon2id hashing, CSRF-guarded writes, session security
- 🧱 **Zero runtime dependencies** — PHP + PDO; Composer only for dev/tests

## Quick start

```bash
git clone https://github.com/DanMat/NimbusCMS.git && cd NimbusCMS
cp .env.example .env
docker compose up -d --build          # app :8080 · adminer :8081 · mysql :3307
docker compose exec app php bin/nimbus install
```

Open **http://localhost:8080/admin** and sign in with `admin@nimbus.test` / `password`.

### CLI

```bash
php bin/nimbus migrate                                 # run pending migrations
php bin/nimbus install --email=you@site.com --password=secret
php bin/nimbus create-user --email=ed@site.com --role=editor
```

## Architecture

A thin, layered kernel — easy to read, easy to extend:

```
public/index.php ─▶ Application (router)
                      ├─ Admin\*        the admin area (auth, dashboard, sections)
                      ├─ Api\*          headless JSON API            (roadmap)
                      ├─ Site\*         public front-end             (roadmap)
                      ├─ Content\*      collections, fields, entries (roadmap)
                      ├─ Media\*        uploads + library            (roadmap)
                      ├─ Auth\*         argon2id sessions
                      ├─ Database\*     PDO facade + migrations
                      └─ View\*         theme templates + themes/nimbus
```

Content lives in JSON columns (`nb_entries.data`) keyed by collection, so adding a
field never means an `ALTER TABLE`.

## Roadmap

- [x] Foundation: Docker stack, migrations, auth, themed admin shell
- [x] Collections & fields (content types) + entry CRUD — extensible field-type registry, per-collection role permissions, event hooks
- [ ] Media library
- [ ] Headless JSON API + tokens
- [ ] Rich-text / Markdown editor
- [ ] RBAC + revisions + activity log
- [ ] Themeable public site
- [ ] Theme management (presets, dark mode, per-user)
- [ ] End-to-end tests + CI, docs, `AGENTS.md`

## License

[MIT](LICENSE) © DanMat
