# Contributing to NimbusCMS

Thanks for your interest. NimbusCMS is a **lightweight, general-purpose PHP CMS**
with a small, readable core and a deliberately narrow plugin boundary. Its guiding
rule: *opinionated about architecture, unopinionated about what people build with
it.* Please read [`docs/CHARTER.md`](docs/CHARTER.md) before proposing a change —
it is the gate every change is measured against.

## Before you start

- **Discuss non-trivial work first.** Open an issue for a bug, or a discussion for
  a feature, before writing a large change — especially anything touching the
  plugin API, auth, the write API, or the database. A small PR that lands beats a
  large one that stalls.
- **Most things are not core.** New capabilities usually belong in a plugin, a
  theme, or application code — not the core. See the classification and the
  "Platform Drift Guard" in [`docs/CHARTER.md`](docs/CHARTER.md).
- **Don't relitigate a merged ADR.** Decisions live in [`docs/adr/`](docs/adr);
  read the relevant one first. Propose a new ADR to change a decision.

## Development setup

Everything runs in Docker — you need only Docker + Compose.

```bash
docker compose up -d              # PHP 8.2+, MySQL 8, Adminer
docker compose exec app composer install
docker compose exec app php bin/nimbus install --email=you@example.com --password='a long unique passphrase'
```

The app is at `http://localhost:8080`, the admin at `/admin`.

## The quality gate

Every change must pass the same gate CI runs. Run it before you push:

```bash
docker compose exec app composer check   # composer audit + PHPStan L6 + full test suite
docker compose exec app composer format   # PHP-CS-Fixer (apply); `format:check` to dry-run
```

- **Tests** (`tests/`): unit, integration (real MySQL), and HTTP-functional
  (real router + kernel). A new behavior needs a test; a bug fix needs a
  regression test that fails before your change and passes after.
- **Static analysis**: PHPStan level 6, clean.
- **Style**: PHP-CS-Fixer, no diff.
- **Boundary tests**: `tests/smoke.sh` (install + CRUD) and
  `tests/Integration/package-boundary.sh` (the plugin API surface) run in CI.

## Conventions

- **PHP 8.2+**, `declare(strict_types=1)`, typed signatures, small classes.
- **Match the surrounding code** — its naming, comment density, and idiom. Read a
  neighbouring file before adding one.
- **Escape at the boundary.** Output is escaped (`View::e`), SQL is parameter-bound
  (`Connection`), redirects/headers reject CR/LF. Never build SQL or HTML by
  string concatenation with untrusted input.
- **Public surfaces are contracts.** The plugin PHP API and the `/api/v1` wire
  contract are documented in [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md).
  Changing them is a deliberate, documented act — see the versioning note there.
- **The database is internal.** Read content through services, never `nb_*` tables
  directly.

## Pull requests

- Branch off `main`; keep PRs small and focused (one concern).
- Fill in the PR template: what changed, why, how it was tested.
- Reference the issue/ADR it addresses.
- CI must be green. Maintainer review is required to merge.

## Security

**Do not open a public issue for a vulnerability.** Follow
[`SECURITY.md`](SECURITY.md) for private disclosure.

## Code of Conduct

Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md).

## Licensing

NimbusCMS is MIT-licensed. By contributing, you agree your contributions are
licensed under the same terms.
