<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/**
 * GitHub via OAuth2. GitHub is a confidential client — the `client_secret` is the
 * primary defense at token exchange; we also send a PKCE challenge/verifier as
 * defense-in-depth (GitHub ignores it where unsupported, no harm). Identity comes
 * from the user API; the email comes from the emails API so we can require a
 * **verified primary** — an unverified GitHub email is never trusted (ADR 0012).
 * The subject we key on is GitHub's immutable numeric `id`, never the mutable
 * `login` handle.
 */
final class GitHubProvider implements OAuthProvider
{
    private const AUTHORIZE = 'https://github.com/login/oauth/authorize';
    private const TOKEN     = 'https://github.com/login/oauth/access_token';
    private const USER      = 'https://api.github.com/user';
    private const EMAILS    = 'https://api.github.com/user/emails';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
    ) {
    }

    public function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id'             => $this->clientId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => 'read:user user:email',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'allow_signup'          => 'false',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, string $codeVerifier, string $redirectUri): string
    {
        $res = OAuthHttp::postForm(self::TOKEN, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
        ]);

        $token = $res['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OAuthException('GitHub did not return an access token.');
        }
        return $token;
    }

    public function identity(string $accessToken): OAuthIdentity
    {
        $u = OAuthHttp::getJson(self::USER, $accessToken);

        $id = $u['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && $id !== '')) {
            throw new OAuthException('GitHub user response was missing an id.');
        }
        $subject = (string) $id;

        [$email, $verified] = $this->primaryVerifiedEmail($accessToken);

        return new OAuthIdentity(
            providerUserId: $subject,
            email: $email,
            emailVerified: $verified,
            name: is_string($u['name'] ?? null) ? (string) $u['name'] : (is_string($u['login'] ?? null) ? (string) $u['login'] : ''),
        );
    }

    /**
     * @return array{0:string,1:bool} the primary email and whether it is verified
     */
    private function primaryVerifiedEmail(string $accessToken): array
    {
        $rows = OAuthHttp::getJson(self::EMAILS, $accessToken);
        // getJson decodes a JSON array into a list; iterate its entries.
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['primary'] ?? false) === true && is_string($row['email'] ?? null)) {
                return [(string) $row['email'], ($row['verified'] ?? false) === true];
            }
        }
        return ['', false];
    }
}
