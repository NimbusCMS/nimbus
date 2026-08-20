<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * Rate limiting through the real kernel. Low limits are set via env for the
 * duration of each test and cleared afterwards.
 */
final class ApiRateLimitTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('API_RATE_LIMIT=3');   // per-token quota
        putenv('API_FLOOD_LIMIT=5');  // per-IP flood ceiling
        putenv('API_RATE_WINDOW=60');

        $this->tokens = new ApiTokenRepository($this->db);
        $this->token  = $this->tokens->create('T', ['*:read']);
        $this->makeCollection('posts');
    }

    protected function tearDown(): void
    {
        putenv('API_RATE_LIMIT');
        putenv('API_FLOOD_LIMIT');
        putenv('API_RATE_WINDOW');
        parent::tearDown();
    }

    private function apiGet(?string $token, string $ip = '203.0.113.9'): Response
    {
        $server = ['REMOTE_ADDR' => $ip];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        return $this->throughKernel(new Request('GET', '/api/v1/collections/posts/entries', [], [], $server, []));
    }

    public function test_a_token_is_held_to_its_quota(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(200, $this->apiGet($this->token)->status, "request {$i} is within quota");
        }

        $limited = $this->apiGet($this->token);
        self::assertSame(429, $limited->status, 'the 4th request is rate-limited');
        self::assertSame('rate_limited', json_decode($limited->body, true)['error']['code']);
        self::assertNotNull($limited->header('Retry-After'));
        self::assertSame('3', $limited->header('X-RateLimit-Limit'));
    }

    public function test_the_flood_guard_catches_unauthenticated_requests_before_auth(): void
    {
        // No token: each request is a 401, but the per-IP flood guard runs first
        // and counts it — so a burst from one IP is turned away.
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(401, $this->apiGet(null)->status, "unauth request {$i} reaches auth");
        }
        self::assertSame(429, $this->apiGet(null)->status, 'the 6th from this IP is flooded');
    }

    public function test_a_different_ip_has_its_own_flood_bucket(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->apiGet(null, '203.0.113.9');
        }
        self::assertSame(401, $this->apiGet(null, '198.51.100.7')->status, 'another IP is unaffected');
    }
}
