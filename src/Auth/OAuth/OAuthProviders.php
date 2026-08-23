<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

use Nimbus\Support\Config;

/**
 * The set of SSO providers this install has configured (ADR 0012). Built from
 * env by {@see fromConfig()} — which only includes a provider whose credentials
 * are present — or from an explicit map in tests (a fake provider). SSO is off
 * when this is empty.
 */
final class OAuthProviders
{
    /** @param array<string,OAuthProvider> $providers keyed by provider key */
    public function __construct(private array $providers)
    {
    }

    public static function fromConfig(): self
    {
        $providers = [];
        foreach (Config::oauthProviders() as $key => $c) {
            $provider = match ($key) {
                'google' => new GoogleProvider($c['id'], $c['secret']),
                'github' => new GitHubProvider($c['id'], $c['secret']),
                default  => null,
            };
            if ($provider !== null) {
                $providers[$key] = $provider;
            }
        }
        return new self($providers);
    }

    public function enabled(): bool
    {
        return $this->providers !== [];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): ?OAuthProvider
    {
        return $this->providers[$key] ?? null;
    }

    /** @return array<string,OAuthProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
