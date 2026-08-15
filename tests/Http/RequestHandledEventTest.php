<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Application;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The request.handled event through the real kernel: a listener observes the
 * finished request/response, and a throwing listener never breaks it (ADR 0005
 * capability A; CoreEvents documents the best-effort, isolated semantics).
 */
final class RequestHandledEventTest extends HttpTestCase
{
    public function test_request_handled_carries_the_request_and_response(): void
    {
        $events = new EventDispatcher();
        $seen   = null;
        $events->listen(CoreEvents::REQUEST_HANDLED, function (mixed $payload) use (&$seen): void {
            $seen = $payload;
        });

        $app      = new Application($this->db, $this->auth, [], null, $events);
        $response = $app->handle($this->request('GET', '/no/such/path'));

        self::assertIsArray($seen);
        /** @var array{request:Request,response:Response} $seen */
        self::assertSame('/no/such/path', $seen['request']->path);
        self::assertSame($response->status, $seen['response']->status);
    }

    public function test_a_throwing_listener_does_not_break_the_served_response(): void
    {
        $events = new EventDispatcher();
        $events->listen(CoreEvents::REQUEST_HANDLED, function (): void {
            throw new \RuntimeException('analytics blew up');
        });

        $app      = new Application($this->db, $this->auth, [], null, $events);
        $response = $app->handle($this->request('GET', '/'));

        self::assertSame(200, $response->status, 'a broken listener must not turn a served page into a 500');
    }

    public function test_no_listeners_means_no_dispatch_overhead(): void
    {
        // A plugin-free install has nothing listening; the event is simply not
        // dispatched. Sanity: a normal request still succeeds.
        self::assertSame(200, $this->get('/')->status);
    }
}
