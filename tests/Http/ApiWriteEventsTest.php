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
 * The api.entry_written event an audit plugin listens to — it names the acting
 * token and fires only on a successful write.
 */
final class ApiWriteEventsTest extends HttpTestCase
{
    private EventDispatcher $events;
    private ApiTokenRepository $tokens;
    private string $token;

    /** @var list<array<string,mixed>> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new EventDispatcher();
        $this->events->listen(CoreEvents::API_ENTRY_WRITTEN, function (mixed $payload): void {
            if (is_array($payload)) {
                $this->written[] = $payload;
            }
        });
        $this->tokens = new ApiTokenRepository($this->db);
        $this->token  = $this->tokens->create('CI', ['posts:write']);
        $this->makeCollection('posts');
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    private function send(string $method, string $path, array $body, array $headers = [], ?string $token = null): Response
    {
        $server = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token)];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $app = new Application($this->db, $this->auth, null, null, $this->events);
        return $app->handle(new Request($method, $path, [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function test_a_create_announces_a_write_naming_the_token(): void
    {
        $response = $this->send('POST', '/api/v1/collections/posts/entries', ['title' => 'Hello', 'fields' => []]);
        self::assertSame(201, $response->status);

        self::assertCount(1, $this->written);
        $event = $this->written[0];
        self::assertSame('create', $event['action']);
        self::assertSame('posts', $event['collection']);
        self::assertSame('CI', $event['token_name']);
        self::assertSame('198.51.100.9', $event['ip']);
        self::assertNotSame('', $event['slug']);
        self::assertGreaterThan(0, $event['entry_id']);
    }

    public function test_update_and_delete_announce_their_actions(): void
    {
        $create = $this->send('POST', '/api/v1/collections/posts/entries', ['title' => 'Hello', 'fields' => []]);
        $slug   = json_decode($create->body, true)['data']['slug'];
        $path   = "/api/v1/collections/posts/entries/{$slug}";
        $this->written = [];

        $updated = $this->send('PATCH', $path, ['title' => 'Edited'], ['If-Match' => (string) $create->header('ETag')]);
        self::assertSame(200, $updated->status);

        $deleted = $this->send('DELETE', $path, [], ['If-Match' => (string) $updated->header('ETag')]);
        self::assertSame(204, $deleted->status);

        self::assertSame(['update', 'delete'], array_column($this->written, 'action'));
    }

    public function test_a_refused_write_announces_nothing(): void
    {
        $readOnly = $this->tokens->create('R', ['posts:read']);

        $response = $this->send('POST', '/api/v1/collections/posts/entries', ['title' => 'X', 'fields' => []], [], $readOnly);

        self::assertSame(403, $response->status);
        self::assertSame([], $this->written, 'a write that never happened is never announced');
    }
}
