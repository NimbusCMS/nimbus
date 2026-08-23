<?php

declare(strict_types=1);

namespace Nimbus\Tests\Support;

use Nimbus\Auth\OAuth\OAuthException;
use Nimbus\Auth\OAuth\OAuthIdentity;
use Nimbus\Auth\OAuth\OAuthProvider;

/**
 * A network-free provider for driving the OAuth flow in tests. It records the
 * front-channel arguments (state / challenge) so a test can assert PKCE + state
 * were sent, and returns a fixed identity from {@see identity()} — or throws to
 * simulate a provider/token failure.
 */
final class FakeOAuthProvider implements OAuthProvider
{
    public ?string $lastState     = null;
    public ?string $lastChallenge = null;
    public ?string $lastVerifier  = null;
    public bool $failExchange     = false;

    public function __construct(
        private string $key,
        private OAuthIdentity $identity,
        private string $label = 'Fake',
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri): string
    {
        $this->lastState     = $state;
        $this->lastChallenge = $codeChallenge;
        return 'https://provider.test/authorize?state=' . urlencode($state) . '&code_challenge=' . urlencode($codeChallenge);
    }

    public function exchange(string $code, string $codeVerifier, string $redirectUri): string
    {
        if ($this->failExchange) {
            throw new OAuthException('simulated exchange failure');
        }
        $this->lastVerifier = $codeVerifier;
        return 'fake-access-token';
    }

    public function identity(string $accessToken): OAuthIdentity
    {
        return $this->identity;
    }
}
