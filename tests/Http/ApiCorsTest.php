<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * Minimal CORS for the read API, driven by the origin allow-list.
 */
final class ApiCorsTest extends HttpTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('CORS_ALLOWED_ORIGINS=https://app.example');
        $this->token = (new ApiTokenRepository($this->db))->create('T', ['*:read']);
        $this->makeCollection('posts');
    }

    protected function tearDown(): void
    {
        putenv('CORS_ALLOWED_ORIGINS');
        parent::tearDown();
    }

    /** @param array<string,string> $server */
    private function send(string $method, array $server): Response
    {
        return $this->throughKernel(new Request($method, '/api/v1/collections/posts/entries', [], [], $server + ['REMOTE_ADDR' => '127.0.0.1'], []));
    }

    public function test_a_preflight_from_an_allowed_origin_is_answered_before_auth(): void
    {
        $response = $this->send('OPTIONS', ['HTTP_ORIGIN' => 'https://app.example']);

        self::assertSame(204, $response->status, 'a preflight needs no token');
        self::assertSame('https://app.example', $response->header('Access-Control-Allow-Origin'));
        self::assertStringContainsString('GET', (string) $response->header('Access-Control-Allow-Methods'));
    }

    public function test_an_actual_response_from_an_allowed_origin_is_annotated(): void
    {
        $response = $this->send('GET', ['HTTP_ORIGIN' => 'https://app.example', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        self::assertSame(200, $response->status);
        self::assertSame('https://app.example', $response->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->header('Vary'));
    }

    public function test_a_disallowed_origin_gets_no_cors_headers(): void
    {
        $response = $this->send('GET', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        self::assertSame(200, $response->status, 'the request still succeeds — the browser is what blocks it');
        self::assertNull($response->header('Access-Control-Allow-Origin'), 'but no origin is granted');
    }
}
