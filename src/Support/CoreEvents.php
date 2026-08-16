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

    /**
     * A request has been handled and its response built. Payload:
     * `['request' => Request, 'response' => Response]`.
     *
     * Unlike the entry events above, this one is **best-effort and isolated**,
     * not post-commit-and-propagating: it fires after the response is already
     * finished, so a throwing listener is caught and logged — it can never turn
     * a served page into a 500. It fires for *every* request (admin, API, asset,
     * public); a listener filters on `$request->path` for what it cares about.
     * Suited to observation — analytics, access logging — not to changing the
     * response.
     */
    public const REQUEST_HANDLED = 'request.handled';

    /**
     * An API request was refused authentication — no bearer token, or one that
     * did not resolve (invalid / expired / revoked / paused, all indistinguishable
     * to the caller). Payload: `['reason' => 'missing'|'invalid', 'ip' => string,
     * 'path' => string, 'at' => 'Y-m-d H:i:s']` — **never the presented token**.
     *
     * Best-effort and isolated, like {@see REQUEST_HANDLED}. It fires *after* the
     * per-IP flood guard, so a flood is already `429`'d before it ever emits — but
     * a listener that records these must still aggregate, since one event per
     * refused request is otherwise a denial-of-service amplifier. Names/payloads
     * are pre-1.0.
     */
    public const API_TOKEN_REJECTED = 'api.token_rejected';

    /**
     * A *valid* API token was refused an action by scope. Payload:
     * `['token_id' => int, 'token_name' => string, 'resource' => string,
     * 'action' => string, 'ip' => string, 'path' => string, 'at' => 'Y-m-d H:i:s']`.
     * Best-effort and isolated; pre-1.0. The consumer of choice is an audit /
     * activity-log plugin.
     */
    public const API_ACCESS_DENIED = 'api.access_denied';

    private function __construct()
    {
    }
}
