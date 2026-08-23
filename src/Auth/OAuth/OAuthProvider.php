<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/**
 * One external identity provider (ADR 0012). The whole SSO flow depends only on
 * this interface, so tests drive it with a fake — no network — and a new provider
 * is one adapter. Built-in adapters: {@see GoogleProvider}, {@see GitHubProvider}.
 *
 * `authorizeUrl` is pure (front-channel URL, no secret). `exchange` and
 * `identity` are the two server-to-server, TLS-verified calls; `exchange` returns
 * the access token, `identity` reads the userinfo. A failure throws
 * {@see OAuthException}.
 */
interface OAuthProvider
{
    /** Stable key used in routes/config/storage, e.g. `google`. */
    public function key(): string;

    /** Human label for the button, e.g. `Google`. */
    public function label(): string;

    /** The provider's authorization URL (front-channel): client_id + redirect + state + PKCE S256 challenge. No secret. */
    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri): string;

    /** Exchange the authorization code (server-side, TLS) for an access token. */
    public function exchange(string $code, string $codeVerifier, string $redirectUri): string;

    /** Fetch the end-user's identity with the access token (server-side, TLS). */
    public function identity(string $accessToken): OAuthIdentity;
}
