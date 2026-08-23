<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

use Nimbus\Support\Config;

/**
 * The OAuth Authorization-Code + PKCE flow (ADR 0012), split into the two
 * server-side halves that bracket the provider round-trip:
 *
 *  - {@see start()} mints a single-use, session-bound `state` and a PKCE verifier,
 *    stashes the whole flow in the session (never the URL), and returns the
 *    provider's authorize URL.
 *  - {@see callback()} consumes that flow, checks it end-to-end (provider binding,
 *    state equality, PKCE), exchanges the code and reads identity over TLS, then
 *    dispatches by the flow's recorded intent.
 *
 * Phase 1 intents are only `login` (sign in an already-linked identity) and
 * `link` (a logged-in user connects a provider). An unknown identity is rejected
 * — no auto-provision, and email is never a matching key.
 */
final class OAuthService
{
    private const SESSION_KEY = 'nimbus_oauth_flow';

    public const INTENT_LOGIN = 'login';
    public const INTENT_LINK  = 'link';

    public function __construct(
        private OAuthProviders $providers,
        private OAuthIdentityRepository $identities,
    ) {
    }

    /**
     * Begin a flow and return the provider authorize URL to redirect to. A `link`
     * intent must carry the initiating user's id; it is stored in the session (not
     * the URL) so the callback can prove the link is bound to the same session.
     *
     * @throws OAuthException when the provider is not configured or the intent is invalid
     */
    public function start(string $providerKey, string $intent, ?int $currentUserId, string $rawNext): string
    {
        $provider = $this->providers->get($providerKey);
        if ($provider === null) {
            throw new OAuthException('Unknown or unconfigured provider.');
        }
        if ($intent !== self::INTENT_LOGIN && $intent !== self::INTENT_LINK) {
            throw new OAuthException('Invalid OAuth intent.');
        }
        if ($intent === self::INTENT_LINK && $currentUserId === null) {
            throw new OAuthException('Linking requires an authenticated user.');
        }

        $state    = self::b64url(random_bytes(32));
        $verifier = self::b64url(random_bytes(64));
        $challenge = self::b64url(hash('sha256', $verifier, true));

        $_SESSION[self::SESSION_KEY] = [
            'provider' => $providerKey,
            'intent'   => $intent,
            'state'    => $state,
            'verifier' => $verifier,
            'uid'      => $currentUserId,
            'next'     => self::safeNext($rawNext),
        ];

        return $provider->authorizeUrl($state, $challenge, self::redirectUri($providerKey));
    }

    /**
     * Complete a flow. The stashed flow is consumed (single-use) before anything
     * else, so a replayed or forged callback finds nothing. `$currentUserId` is
     * the presently authenticated user (if any) — a `link` must match the user who
     * started it.
     */
    public function callback(string $providerKey, string $code, string $state, ?int $currentUserId): OAuthResult
    {
        $flow = $this->consumeFlow();

        // Provider binding, single-use state equality, and a well-formed code.
        if ($flow === null || !hash_equals((string) $flow['provider'], $providerKey)) {
            return new OAuthResult(OAuthOutcome::InvalidState);
        }
        if (!is_string($flow['state']) || !hash_equals($flow['state'], $state)) {
            return new OAuthResult(OAuthOutcome::InvalidState);
        }

        $provider = $this->providers->get($providerKey);
        if ($provider === null) {
            return new OAuthResult(OAuthOutcome::NotConfigured);
        }
        $label = $provider->label();

        if ($code === '') {
            return new OAuthResult(OAuthOutcome::ProviderError, providerLabel: $label);
        }

        try {
            $token    = $provider->exchange($code, (string) $flow['verifier'], self::redirectUri($providerKey));
            $identity = $provider->identity($token);
        } catch (OAuthException) {
            return new OAuthResult(OAuthOutcome::ProviderError, providerLabel: $label);
        }

        $intent = (string) $flow['intent'];
        $next   = is_string($flow['next'] ?? null) ? $flow['next'] : '/admin';

        if ($intent === self::INTENT_LINK) {
            return $this->resolveLink($flow, $currentUserId, $providerKey, $identity, $label);
        }

        return $this->resolveLogin($providerKey, $identity, $next, $label);
    }

    private function resolveLogin(string $providerKey, OAuthIdentity $identity, string $next, string $label): OAuthResult
    {
        $userId = $this->identities->userIdFor($providerKey, $identity->providerUserId);
        if ($userId === null) {
            // Phase 1: no auto-provision, no email fallback — an unknown identity
            // is turned away.
            return new OAuthResult(OAuthOutcome::UnknownIdentity, providerLabel: $label);
        }
        return new OAuthResult(OAuthOutcome::SignedIn, userId: $userId, next: $next, providerLabel: $label);
    }

    /**
     * @param array<string,mixed> $flow
     */
    private function resolveLink(array $flow, ?int $currentUserId, string $providerKey, OAuthIdentity $identity, string $label): OAuthResult
    {
        // The link must belong to the same authenticated user who started it: the
        // uid recorded at start (in-session, not the URL) must equal the caller's
        // current session user. Any drift = reject.
        $uid = is_int($flow['uid'] ?? null) ? $flow['uid'] : null;
        if ($uid === null || $currentUserId === null || $uid !== $currentUserId) {
            return new OAuthResult(OAuthOutcome::InvalidState, providerLabel: $label);
        }

        $linked = $this->identities->link($uid, $providerKey, $identity->providerUserId, $identity->email);
        if (!$linked) {
            // UNIQUE(provider, provider_user_id) — this identity already belongs to
            // some account (possibly this one). Never steal or duplicate it.
            return new OAuthResult(OAuthOutcome::AlreadyLinked, providerLabel: $label);
        }
        return new OAuthResult(OAuthOutcome::Linked, userId: $uid, providerLabel: $label);
    }

    /** @return array<string,mixed>|null */
    private function consumeFlow(): ?array
    {
        $flow = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        return is_array($flow) ? $flow : null;
    }

    /** The registered redirect/callback URI for a provider, derived from APP_URL (not the Host header). */
    public static function redirectUri(string $providerKey): string
    {
        return Config::appUrl() . '/admin/oauth/' . $providerKey . '/callback';
    }

    /**
     * Constrain a post-login redirect to an internal absolute path: one leading
     * slash, no scheme, no `//` (scheme-relative), no backslash, no control
     * characters. Anything else falls back to the dashboard — the open-redirect
     * guard for `next`.
     */
    private static function safeNext(string $next): string
    {
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
            return '/admin';
        }
        if (str_contains($next, '\\') || preg_match('/[\x00-\x1f]/', $next) === 1) {
            return '/admin';
        }
        return $next;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
