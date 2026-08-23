<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/**
 * Google via OpenID Connect. We use the Authorization-Code flow with PKCE and
 * read identity from the **userinfo** endpoint — not by validating an id_token
 * JWT locally (ADR 0012: provenance comes from the TLS-verified token endpoint,
 * which keeps core dependency-free). The subject we key on is Google's stable
 * `sub`.
 */
final class GoogleProvider implements OAuthProvider
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN     = 'https://oauth2.googleapis.com/token';
    private const USERINFO  = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
    ) {
    }

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google';
    }

    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id'             => $this->clientId,
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'access_type'           => 'online',
            'prompt'                => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, string $codeVerifier, string $redirectUri): string
    {
        $res = OAuthHttp::postForm(self::TOKEN, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
        ]);

        $token = $res['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OAuthException('Google did not return an access token.');
        }
        return $token;
    }

    public function identity(string $accessToken): OAuthIdentity
    {
        $u = OAuthHttp::getJson(self::USERINFO, $accessToken);

        $sub = $u['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new OAuthException('Google userinfo was missing a subject.');
        }

        return new OAuthIdentity(
            providerUserId: $sub,
            email: is_string($u['email'] ?? null) ? (string) $u['email'] : '',
            emailVerified: ($u['email_verified'] ?? false) === true || ($u['email_verified'] ?? null) === 'true',
            name: is_string($u['name'] ?? null) ? (string) $u['name'] : '',
        );
    }
}
