<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/** How an OAuth callback resolved (ADR 0012). The controller maps each to a response. */
enum OAuthOutcome
{
    /** A linked identity signed in — establish the session for {@see OAuthResult::$userId}. */
    case SignedIn;
    /** A logged-in user connected a provider to their account. */
    case Linked;
    /** The identity is not linked to any user — Phase 1 rejects it (no auto-provision). */
    case UnknownIdentity;
    /** The identity is already connected to a Nimbus account (UNIQUE conflict on link). */
    case AlreadyLinked;
    /** state/PKCE/session flow did not validate — a replay, forgery, or expired flow. */
    case InvalidState;
    /** The provider call failed, or the user declined at the provider. */
    case ProviderError;
    /** The named provider is not configured on this install. */
    case NotConfigured;
}
