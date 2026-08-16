<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Application;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The API failure events (api.token_rejected / api.access_denied) an audit
 * plugin listens to. They are best-effort and isolated, and never carry a token.
 */
final class ApiFailureEventsTest extends HttpTestCase
{
    private EventDispatcher $events;
    private ApiTokenRepository $tokens;

    /** @var list<array{event:string,payload:array<string,mixed>}> */
    private array $heard = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new EventDispatcher();
        $this->tokens = new ApiTokenRepository($this->db);

        foreach ([CoreEvents::API_TOKEN_REJECTED, CoreEvents::API_ACCESS_DENIED] as $event) {
            $this->events->listen($event, function (mixed $payload, string $event): void {
                $this->heard[] = ['event' => $event, 'payload' => is_array($payload) ? $payload : []];
            });
        }
        $this->makeCollection('posts');
    }

    /** @param array<string,string> $server */
    private function send(string $path, array $server): Response
    {
        $app = new Application($this->db, $this->auth, null, null, $this->events);
        return $app->handle(new Request('GET', $path, [], [], $server + ['REMOTE_ADDR' => '203.0.113.5'], []));
    }

    public function test_a_missing_token_announces_a_rejection_without_a_token(): void
    {
        $this->send('/api/v1/collections/posts/entries', []);

        self::assertCount(1, $this->heard);
        self::assertSame(CoreEvents::API_TOKEN_REJECTED, $this->heard[0]['event']);
        self::assertSame('missing', $this->heard[0]['payload']['reason']);
        self::assertSame('203.0.113.5', $this->heard[0]['payload']['ip']);
        self::assertArrayNotHasKey('token', $this->heard[0]['payload'], 'a rejection never carries the presented token');
    }

    public function test_an_invalid_token_announces_an_invalid_rejection(): void
    {
        $this->send('/api/v1/collections/posts/entries', ['HTTP_AUTHORIZATION' => 'Bearer nbt_' . str_repeat('0', 40)]);

        self::assertSame('invalid', $this->heard[0]['payload']['reason']);
    }

    public function test_a_scope_denial_announces_access_denied_with_the_token(): void
    {
        $this->makeCollection('pages');
        $token = $this->tokens->create('CI pipeline', ['posts:read']);

        $this->send('/api/v1/collections/pages/entries', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertCount(1, $this->heard);
        self::assertSame(CoreEvents::API_ACCESS_DENIED, $this->heard[0]['event']);
        self::assertSame('pages', $this->heard[0]['payload']['resource']);
        self::assertSame('read', $this->heard[0]['payload']['action']);
        self::assertSame('CI pipeline', $this->heard[0]['payload']['token_name']);
    }

    public function test_a_permitted_request_announces_nothing(): void
    {
        $token = $this->tokens->create('CI', ['posts:read']);

        $this->send('/api/v1/collections/posts/entries', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertSame([], $this->heard, 'a permitted request is silent');
    }

    public function test_a_throwing_listener_never_breaks_the_response(): void
    {
        $this->events->listen(CoreEvents::API_TOKEN_REJECTED, static function (): void {
            throw new \RuntimeException('boom');
        });

        $response = $this->send('/api/v1/collections/posts/entries', []);

        self::assertSame(401, $response->status, 'an isolated event cannot turn the 401 into a 500');
    }
}
