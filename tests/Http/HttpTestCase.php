<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Application;
use Nimbus\Auth\Auth;
use Nimbus\Auth\Password;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Tests\Integration\IntegrationTestCase;

/**
 * Base for tests that drive real Requests through the real router and assert on
 * the Response that comes back.
 *
 * Nothing is mocked: the same controllers, services, repositories and database
 * the web entry point uses. Sessions are real too (cookies disabled, since CLI
 * has nowhere to send them), so CSRF and session rotation behave as they do in
 * a browser.
 */
abstract class HttpTestCase extends IntegrationTestCase
{
    protected Auth $auth;
    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp(); // truncates every nb_ table
        $this->resetSession();

        $this->auth   = new Auth($this->db);
        $this->rebuildRouter();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------- session

    protected function resetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        session_start();
        $_SESSION = [];
    }

    protected function sessionId(): string
    {
        return (string) session_id();
    }

    // ----------------------------------------------------------- requests

    /** @param array<string,mixed> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->throughKernel($this->request('GET', $path, $query, []));
    }

    /**
     * POST with a valid CSRF token unless one is supplied. Tokens are opt-out
     * so that a test about permissions isn't accidentally a test about CSRF.
     *
     * @param array<string,mixed> $body
     */
    protected function post(string $path, array $body = []): Response
    {
        $body['_token'] ??= Csrf::token();
        return $this->throughKernel($this->request('POST', $path, [], $body));
    }

    /**
     * POST with no CSRF token at all.
     *
     * @param array<string,mixed> $body
     */
    protected function postWithoutCsrf(string $path, array $body = []): Response
    {
        unset($body['_token']);
        return $this->throughKernel($this->request('POST', $path, [], $body));
    }

    /** Dispatch against the bare router, without the kernel's error handling. */
    protected function dispatchRaw(string $method, string $path): ?Response
    {
        return $this->router->dispatch($this->request($method, $path));
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    protected function request(string $method, string $path, array $query = [], array $body = []): Request
    {
        return new Request($method, $path, $query, $body, ['REMOTE_ADDR' => '127.0.0.1'], []);
    }

    /**
     * The real kernel path: routes are registered exactly as public/index.php
     * registers them, and every exit is a Response (404, HttpException, 500).
     */
    protected function throughKernel(Request $request): Response
    {
        return (new Application($this->db, $this->auth))->handle($request);
    }

    // -------------------------------------------------------------- users

    protected function createUser(string $role = 'admin', string $email = 'admin@test.local', string $password = 'correct-horse'): int
    {
        return $this->db->insert(
            'INSERT INTO nb_users (name, email, password, role, created_at, updated_at) VALUES (:n, :e, :p, :r, NOW(), NOW())',
            ['n' => ucfirst($role), 'e' => $email, 'p' => Password::hash($password), 'r' => $role]
        );
    }

    /** Authenticate without going through the login form. */
    protected function actingAs(string $role = 'admin', string $email = 'admin@test.local'): int
    {
        $id = $this->createUser($role, $email);
        $_SESSION['nimbus_uid'] = $id;
        $this->auth = new Auth($this->db);
        $this->rebuildRouter();
        return $id;
    }

    /**
     * The exact route table the application serves. Controllers capture Auth at
     * construction, so a new identity needs a fresh table.
     */
    protected function rebuildRouter(): void
    {
        $this->router = (new Application($this->db, $this->auth))->routes();
    }

    // -------------------------------------------------------- fixtures

    /**
     * @param array<int,array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}> $fields
     * @param array<string,mixed> $options
     */
    protected function makeCollection(string $handle, array $fields = [], array $options = []): Collection
    {
        $repo = new CollectionRepository($this->db);
        $id   = (new CollectionService($this->db, $repo))->create(
            $handle,
            ucfirst($handle),
            '#',
            '',
            $options ?: ['kind' => 'collection', 'permissions' => ['manage' => ['editor']]],
            $fields,
        );
        return $repo->find($id);
    }

    // ------------------------------------------------------------ asserts

    protected function assertRedirects(?Response $response, string $to, string $message = ''): void
    {
        self::assertNotNull($response, 'expected a response, got no route match');
        self::assertSame(302, $response->status, $message);
        self::assertSame($to, $response->header('Location'), $message);
    }

    protected function assertRedirectsTo(?Response $response, string $prefix): void
    {
        self::assertNotNull($response);
        self::assertSame(302, $response->status);
        self::assertStringStartsWith($prefix, (string) $response->header('Location'));
    }

    protected function assertOkHtml(?Response $response): Response
    {
        self::assertNotNull($response, 'expected a response, got no route match');
        self::assertSame(200, $response->status);
        self::assertSame('text/html; charset=UTF-8', $response->header('Content-Type'));
        return $response;
    }

    protected function entryCount(int $collectionId): int
    {
        return (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM nb_entries WHERE collection_id = :c',
            ['c' => $collectionId]
        )['c'];
    }
}
