# Roles & capabilities

How NimbusCMS decides who may do what — for both **people** (signed-in admin
users) and **machines** (API tokens). This is the practical guide; the design
rationale is [ADR 0011](adr/0011-roles.md), and the *authoritative* behaviour is
the test suite (linked throughout) — where this page and the tests ever disagree,
the tests win.

> **Stability (pre-1.0).** The authorization **model and its guarantees** —
> described below — are what Nimbus commits to. The specific capability **names**
> and the role **schema** may still change before `1.0`, like the rest of the
> `0.x` surface (see [COMPATIBILITY](COMPATIBILITY.md)). Build against the
> guarantees, not the exact strings.

## The one idea: a capability

A **capability** is a single permission, written `resource:action`:

- **`action`** is `read` or `write`.
- **`resource`** is either a **collection handle** (`posts`, `pages`, …) —
  *content* — or one of the fixed **management** capabilities:
  `schema`, `media`, `users`, `tokens`, `settings`, `roles`.
- **`*`** is the content wildcard: `*:read` means "read every collection".
- **`admin`** is the bare super-grant: it permits every action on every resource.

Every authorization decision — for a user or a token — runs through the one
function [`Authorizer::can()`](../src/Auth/Authorizer.php), deny-by-default. Its
rules, in order:

1. `admin` grants everything.
2. an exact `resource:action` grant suffices.
3. a **management** capability needs an exact grant (or `admin`) — **the content
   wildcard never reaches it.** So `*:write` ("write all my content") can *not*
   mint tokens, add users, or change the schema.
4. for **content**, `*:action` grants that action on every collection, and
   `handle:write` **implies** `handle:read` — you cannot edit content you cannot
   read.

That management boundary is the core safety property: there is **no** path from a
content-only actor to a management capability except an explicit grant or `admin`.
Locked by [`TokenPrincipalTest`](../tests/Unit/TokenPrincipalTest.php).

## Roles

A **role** is a named bundle of capabilities. A **user** may hold several roles;
their authority is the **union**. Roles are created and edited by an admin (or any
holder of `roles:write`) on **Admin → Roles**.

### System roles (seeded)

Seeded on install and by `nimbus roles:seed`; **undeletable**. Their exact
capabilities ([`RoleSeeder`](../src/Auth/RoleSeeder.php),
[`RoleSeederTest`](../tests/Integration/RoleSeederTest.php)):

| Role | Capabilities |
|---|---|
| **admin** | `admin` (super-grant) |
| **editor** | `*:read`, `media:read`, `media:write`, plus `handle:write` for each collection whose manage-list named `editor` |
| **author** | `*:read`, `media:read`, `media:write`, plus `handle:write` for each collection whose manage-list named `author` |

Editors and authors are equal at the platform level today; sites differentiate
them per-collection via the manage-list (folded into `handle:write` grants) and
by editing the roles.

### Custom roles

Compose any bundle — e.g. a **read-only** role (`*:read`), a **media manager**
(`media:read`, `media:write`), a **blog editor** (`posts:write`, `media:*`). Two
rules apply when saving:

- **Subset-only:** you can only put a capability into a role that **you yourself
  hold** — and you cannot edit a role that already grants more than you hold. So a
  non-admin with `roles:write` can never mint an `admin` or `schema:write` role.
  ([`RolesController`](../src/Admin/RolesController.php),
  [`RolesEnforcementTest`](../tests/Http/RolesEnforcementTest.php).)
- **Assignment** is subset-only too: assigning a role to a user requires holding
  all of that role's capabilities.

## Tokens

An **API token** (headless API / MCP) is a standalone machine principal. Its
authority is:

> **explicit scopes ∪ the live capabilities of a bound role**

resolved at every request by
[`ApiTokenRepository::principalFor()`](../src/Api/ApiTokenRepository.php).
"Bound role" is **live**: tightening or deleting the role reaches the token at its
next request (deleting it drops the binding → the token falls back to its explicit
scopes; with none, it denies — never a read-all grant). A token is minted with
subset-only over its **whole** granted set (scopes *and* role caps) on every
surface that can mint one. ([`ApiTokenRepositoryTest`](../tests/Integration/ApiTokenRepositoryTest.php),
[`McpAdminToolsTest`](../tests/Http/McpAdminToolsTest.php).)

## The surfaces

The same rules apply wherever authority is granted:

| Surface | Grants / mints | Subset-only |
|---|---|---|
| **Admin UI** — Roles, Users, API tokens | roles, role assignments, tokens | yes ([`RolesController`], [`UsersController`], [`TokensController`]) |
| **MCP** — `mint_token` (role param), `create_user` / `set_role` (role assignment) | tokens, role assignments | yes ([`TokensToolset`], [`UsersToolset`]) — one shared predicate ([`Authorizer::holds`]) |
| **CLI** — `nimbus token:create --role=` | tokens | no — the CLI is a **trusted local operator**, not an attack surface |

[`RolesController`]: ../src/Admin/RolesController.php
[`UsersController`]: ../src/Admin/UsersController.php
[`TokensController`]: ../src/Admin/TokensController.php
[`TokensToolset`]: ../src/Mcp/TokensToolset.php
[`UsersToolset`]: ../src/Mcp/UsersToolset.php
[`Authorizer::holds`]: ../src/Auth/Authorizer.php

## Authorization matrix

A readable view; the **authoritative** matrix is
[`RolesEnforcementTest`](../tests/Http/RolesEnforcementTest.php) (admin sections),
[`MediaRoutesTest`](../tests/Http/MediaRoutesTest.php) (media),
[`TokenAdminTest`](../tests/Http/TokenAdminTest.php) /
[`McpAdminToolsTest`](../tests/Http/McpAdminToolsTest.php) (tokens), and
[`TokenPrincipalTest`](../tests/Unit/TokenPrincipalTest.php) (the vocabulary).

| Actor holds | Manage schema (collections) | Media library | Manage users | Manage roles | Mint tokens | Write collection `posts` | Read collections |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `admin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `*:read` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| `*:write` | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| `posts:write` | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | `posts` only |
| `media:read`, `media:write` | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `users:write` | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| editor / author (seeded) | ❌ | ✅ | ❌ | ❌ | ❌ | manage-list only | ✅ |

(Management reads are explicit too — e.g. listing the media library needs
`media:read`, which `*:read` does **not** confer.)

## Upgrading an existing install

Run `nimbus roles:seed` (and `nimbus migrate`) after upgrading. Migrations are
additive and idempotent; the seed is idempotent and never overwrites a role an
admin has edited. Before roles are seeded, Nimbus falls back to the legacy
administrators-only behaviour, so an un-seeded install is never *less* restrictive
than the capability model.
