# ADR 0002 — Publication lifecycle

- **Status:** Accepted
- **Date:** 2026-08-02
- **Context:** the first headless vertical slice — a read-only API needs a
  precise, correct definition of what content is public before it can expose it.

## Context

Entries have only ever been `draft` or `published`, with `published_at` set to
"now" the moment an entry was published. That is too coarse for a headless CMS:
the API's central question is *"what is public right now?"*, and the answer has
to be unambiguous, cheap to query, and correct without a human in the loop.

Two capabilities are missing:

- **Scheduling** — publish an entry at a future moment.
- **Archiving** — retire a once-public entry without deleting it or pretending
  it was never published.

## Decision

### Stored status is one of three values

`draft`, `published`, `archived`. That is the whole stored vocabulary, on the
existing indexed `nb_entries.status` column. `published_at` (nullable) records
*when* an entry goes, or went, live.

### "Scheduled" is derived, not stored — so there is no cron

An entry is **live** when, and only when:

```sql
status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()
```

A `published` entry whose `published_at` is in the **future** is not live yet;
we surface it as **Scheduled**. When the clock passes `published_at` it becomes
live automatically, because liveness is evaluated at query time — nothing has
to flip a status from "scheduled" to "published".

This is the crux of the decision. The obvious alternative — a `scheduled`
status that a background job promotes to `published` at the appointed time —
was rejected:

- it needs a scheduler (cron, a queue, a daemon) that Nimbus does not have and
  should not require just to read content;
- until the job runs, the database and the truth disagree — an entry can be
  past its publish time but still labelled `scheduled`;
- the job is one more thing to deploy, monitor, and get wrong.

Deriving the state from `published_at` has none of those failure modes. The
same single predicate answers "is this live?" everywhere: the API, the public
site later, and the admin badges.

The four states a user sees are therefore:

| Shown state | Stored `status` | `published_at` |
|-------------|-----------------|----------------|
| Draft       | `draft`         | (ignored)      |
| Scheduled   | `published`     | in the future  |
| Published   | `published`     | now or past    |
| Archived    | `archived`      | (ignored)      |

### Actions map cleanly onto that

- **Publish now** — status `published`, `published_at` = now.
- **Schedule** — status `published`, `published_at` = a chosen future time.
- **Unpublish** — status `draft`. The entry leaves the public set immediately;
  `published_at` is kept, so re-publishing can reuse it.
- **Archive** — status `archived`. Retired, not public, and distinct from a
  draft that was never published.

Only `status` and `published_at` decide liveness. `published_at` may be
back-dated (publish with a past date) or future-dated (schedule) freely.

### Lifecycle fields stay columns, never JSON

`status` and `published_at` are indexed columns, not entries in the `data`
JSON blob. The live query has to be indexable, and lifecycle is a core concern
every collection shares — it is not per-collection field data.

## Consequences

**Good.** The API gets one exact, indexable definition of "public". Scheduling
works with no new infrastructure. There is no window in which the stored state
lies about whether something is live. The admin can show a truthful state badge
computed the same way the API filters.

**Accepted limits.**

- **Minute-granularity, server-clock scheduling.** "Live at 09:00" means when
  the server's clock passes 09:00 and a request evaluates the predicate. Good
  enough for content; not a real-time trigger, and no per-entry timezone yet
  (times are the server's).
- **No `unpublished_at` yet.** Archiving records the status change, not a
  separate retirement timestamp. Adding one later is non-breaking.
- **No auto-transition side effects.** Nothing *happens* at publish time (no
  email, no webhook) because there is no moment we act on — only queries that
  start returning the entry. When webhooks arrive they will need a different,
  event-driven trigger, and that is a deliberate separate decision.

## What this unblocks

The read-only API can define its entry set as exactly the live predicate above,
and be confident a draft or a not-yet-due scheduled entry can never leak.
