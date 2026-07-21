<?php

declare(strict_types=1);

namespace Nimbus\Support;

/**
 * The event names core dispatches. Constants rather than loose strings, because
 * these become a public contract the moment plugins subscribe to them: a typo in
 * a listener currently fails silently (it just never fires), and renaming a
 * string literal scattered across the codebase is how that happens.
 *
 * Semantics — read this before adding a listener:
 *
 * - **Post-commit notification only.** Every event here fires *after* the
 *   transaction committed. The write already happened and cannot be vetoed;
 *   listeners observe, they do not participate. "About to save" hooks
 *   (`entry.saving`) would need a different, pre-commit contract and do not
 *   exist yet.
 * - **Truthful.** An event fires only when the state change really occurred.
 *   Deleting an absent entry dispatches nothing.
 * - **Synchronous, in registration order.** A slow listener slows the request.
 * - **Exceptions propagate.** A throwing listener surfaces through the
 *   application error boundary: logged with a reference id, generic 500 to the
 *   client. Failures are loud rather than swallowed. Since dispatch is
 *   post-commit, a failing listener cannot roll the write back — it can only
 *   fail the response. Isolating or queueing delivery is deliberately deferred
 *   until a real consumer needs it.
 *
 * Payloads are arrays today. They are not frozen; the shapes will be settled
 * before the plugin API is declared stable.
 */
final class CoreEvents
{
    /** A new entry row was inserted. Payload: id, collection_id, title, slug, status. */
    public const ENTRY_CREATED = 'entry.created';

    /** An existing entry row was updated. Payload: id, collection_id, title, slug, status. */
    public const ENTRY_UPDATED = 'entry.updated';

    /** Fires after ENTRY_CREATED or ENTRY_UPDATED. Payload: id, collection_id, created. */
    public const ENTRY_SAVED = 'entry.saved';

    /** An entry row was actually deleted. Payload: id, collection_id. */
    public const ENTRY_DELETED = 'entry.deleted';

    private function __construct()
    {
    }
}
