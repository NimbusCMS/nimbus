# 11. Roles — capability bundles for users and tokens

- **Status:** Proposed (design; slices refine each area). Supersedes the fixed
  three-role model once Slice 1 lands.
- **Date:** 2026-08-18
- **Related:** [ADR 0006](0006-non-human-authentication.md) (token scopes),
  [ADR 0009](0009-mcp-control-surface.md) (the capability vocabulary — designed
  as the atoms of *this*), `src/Content/Permissions.php` (today's enforcement
  point), `src/Api/TokenPrincipal.php` (`can()`).
- **Drives:** letting an administrator define **named roles** — "Blog editor",
  "Media manager", "Support (read-only)" — as bundles of granular capabilities,
  and assign them to **both** people (session users) and machines (API tokens),
  so authority is least-privilege and no longer limited to three hardcoded roles.

## Context

There are two authorization models today, and roles must unify them:

- **Session users** (admin): three fixed roles (`admin` / `editor` / `author`),
  plus a per-collection "who may manage" list. Enforced by `Permissions`
  (`isAdmin`, `canManage`) — whose own docblock calls itself *"the enforcement
  point the granular RBAC UI will build on."*
- **API tokens** (headless / MCP): granular `resource:action` **capabilities**,
  enforced by `TokenPrincipal::can()` (with the `admin` super-grant and the
  wildcard-never-management rule).

The MCP milestone (ADR 0009) already built the capability vocabulary — content
`{handle}:read`/`write` and management `schema:write` / `media:*` / `users:write`
/ `tokens:write` — **explicitly as the atoms of this roles system.** So roles is
mostly *reuse plus a store*, not new authorization theory; it adds one capability,
**`roles:write`** (managing roles), governed by the same subset-only rule as
minting.

This passes the Platform Drift Guard: custom roles are a general CMS need wanted
by any multi-author site, independent of Restaurant / Food Store / Packkit.

## Decision

### A role is a named bundle of capabilities — one vocabulary, users **and** tokens

A `Role` (`nb_roles`: `name`, `capabilities`, `is_system`) is a named set of
capabilities drawn from the single vocabulary. It applies to both principals:

- a **user** is assigned a role; the role resolves to the user's capabilities;
- a **token** may be granted a role (its capabilities expand from the role) or,
  as today, an explicit capability list for one-off grants.

`TokenPrincipal::can()`'s logic becomes a shared **`Authorizer`** so a
`UserPrincipal` answers `can(resource, action)` identically — one deny-by-default
decision function, one place, for people and machines alike.

### Role-centric, for granularity

A role declares **what it can reach**, including `{handle}:write` per collection —
not the inverse "each collection lists its roles". This is the granular RBAC the
brief asked for. The current per-collection manage list is **migrated into roles**
(see below), and the collection form's role picker becomes a role's capability
picker.

### Managing roles is itself capability-gated and subset-only

Creating/editing/deleting a role is the action **`roles:write`** (the `admin`
role holds it). Because a role grants capabilities, this is escalation-sensitive,
so it reuses the token-mint control (ADR 0009): **you can only put a capability
into a role that you yourself hold.** A non-admin with `roles:write` may define
roles, but never one containing `admin` or a capability they lack — no role can
be used to exceed its author. One rule, two surfaces (mint a token, define a
role).

### New collections — no orphaned permissions

A new collection has no role's explicit `{handle}:write`, so the model answers
"who manages it?" two ways the admin chooses **per role**:

- **Broad roles hold the content wildcard `*:write`** — "all collections, present
  *and future*", so a new collection is covered automatically. (`*` is content-
  only, ADR 0009 Slice 1, so it never reaches schema/users/tokens.) The seeded
  `editor` role is `*:write`.
- **Granular roles list explicit `{handle}:write`** — specific collections only;
  a new one is excluded *by design*, because opt-in is the point of granularity.

To spare editing N roles after each new collection, the **collection-creation
form offers a "grant manage to: [roles]" checklist** — a collection-centric
*shortcut* that writes `{handle}:write` into the chosen roles. Storage stays
role-centric; the shortcut just meets the admin where today's role picker does.

### Heterogeneous, per-collection grants

A role's capabilities are an arbitrary set, so grants are naturally mixed —
`posts:write` (edit) **and** `pages:read` (view only) **and** nothing for
`products` (invisible) in one role. Absence is deny-by-default: a collection a
role doesn't list is unreachable — not in the nav, forbidden on a direct hit.
This makes **viewing a collection read-gated** (`{handle}:read`), a deliberate
change from today's "any signed-in user browses everything" (`canView` → always
true). For content, **`{handle}:write` implies `{handle}:read`** — you cannot
edit what you cannot list, matching the admin's existing `canManage ⊇ canView`.

### System roles + a behavior-preserving migration

The three fixed roles are seeded as **system roles** (un-deletable, renamable
later): `admin` → the `admin` super-grant. `editor` / `author` are seeded to
reproduce today's behavior *exactly*: **`*:read`** (they browse every collection
today), **plus** each collection's `{handle}:write` for the collections whose
manage list named them. So a migrated editor is itself a mixed grant — reads all,
writes the ones granted. Existing users keep their role name, now backed by
capabilities. **No one's access changes on upgrade** — the read-gating only bites
for *new*, custom roles an admin deliberately scopes narrower.

### The admin enforcement points become capability checks

`Permissions::isAdmin` / `canManage` and every `requireAdmin` are re-expressed
against `can()` — structural actions map to the same management capabilities MCP
uses (collections → `schema:write`, media → `media:*`, tokens → `tokens:write`,
users → `users:write`), content management → `{handle}:write`. This is done
behind a compatibility bridge so the existing admin tests stay green throughout;
it is the **riskiest slice** and lands on its own.

### Deferred (named, not built now)

Multiple roles per user (start with one, for schema compat); whether a token's
role is a **live reference** (tighten the role → tighten its tokens — the
RBAC-correct default we lean to) or a snapshot, decided in the token slice; a
distinct `settings` capability (waits on the deferred settings store, ADR 0009).

## Consequences

**Enables**
- Least-privilege for real teams and integrations — arbitrary named roles instead
  of three, the same bundle usable for a person or an agent.
- One authorization function for the whole product; the MCP capability model
  finally has the roles layer it was designed for.

**Costs / makes harder**
- Authorization is foundational — the enforcement-point migration (Slice 3) must
  be exactly behavior-preserving, proven by the existing admin/permission tests.
- A migration of collection manage-lists into role capabilities, run once.
- Roles need an assignment UI (and a users admin page — currently users are
  CLI/MCP only), which the milestone adds.

**Out of scope (later, on evidence):** multi-role users; per-entry / ownership
("own posts only") rules beyond collection granularity; a permissions API for
plugins to declare their own capabilities.

## Slices

1. **Role store + shared `Authorizer`** — `nb_roles` + migration seeding the three
   system roles and folding manage-lists into capabilities; extract `can()` into a
   shared authorizer used by `TokenPrincipal` and a new `UserPrincipal`; users
   resolve role → capabilities behind a compat bridge (behavior identical, all
   tests green).
2. **Roles admin UI** — CRUD roles (name + capability checklist, enforcing
   subset-only) + assign a role to a user (with the users admin page this needs) +
   the collection-creation "grant manage to: [roles]" shortcut.
3. **Migrate enforcement to capabilities** — `Permissions` / `requireAdmin` /
   `canManage` become `can()` checks; retire the fixed-role assumptions. The risky
   slice; behavior-preserving.
4. **Roles for tokens** — mint a token as a role (`nimbus token:create --role=`,
   and the MCP `mint_token`), live reference vs snapshot decided here; the mint
   subset-only guard still applies.
5. **Security review + docs** — an authorization-matrix pass (actor × role ×
   resource × action), `docs/ROLES.md`, and a `nimbus-security-review` over the
   whole surface.

Each slice lands CI-green with a `nimbus-security-review` pass — this is the
authorization core of the product.
