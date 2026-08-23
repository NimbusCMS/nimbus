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

    /** Optional mail transport for a test to capture outgoing mail; null → the configured default. */
    protected ?\Nimbus\Mail\Mailer $mailer = null;

    /** Optional SSO providers for a test to drive OAuth with a fake; null → configured (off in tests). */
    protected ?\Nimbus\Auth\OAuth\OAuthProviders $oauthProviders = null;

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
        return (new Application($this->db, $this->auth, mailer: $this->mailer, oauthProviders: $this->oauthProviders))->handle($request);
    }

    // -------------------------------------------------------------- users

    protected function createUser(string $role = 'admin', string $email = 'admin@test.local', string $password = 'correct-horse'): int
    {
        return $this->db->insert(
            'INSERT INTO nb_users (name, email, password, role, created_at, updated_at) VALUES (:n, :e, :p, :r, NOW(), NOW())',
            ['n' => ucfirst($role), 'e' => $email, 'p' => Password::hash($password), 'r' => $role],
        );
    }

    /** Authenticate without going through the login form. */
    protected function actingAs(string $role = 'admin', string $email = 'admin@test.local'): int
    {
        $id = $this->createUser($role, $email);
        // Roles (ADR 0011): assign the matching system role so the capability
        // Gate authorizes this user; base caps mirror RoleSeeder — admin -> admin,
        // editor/author -> *:read + media:read/write (per-collection writes are
        // granted by makeCollection).
        $roles    = new \Nimbus\Auth\RoleRepository($this->db);
        $media    = \Nimbus\Auth\RoleSeeder::CONTENT_MEDIA_CAPS;
        $base     = [
            'admin'  => ['admin'],
            'editor' => array_merge(['*:read'], $media),
            'author' => array_merge(['*:read'], $media),
        ];
        $existing = $roles->findByName($role);
        $roleId   = $existing !== null ? $existing->id : $roles->create($role, $base[$role] ?? [], true);
        $roles->assignToUser($id, $roleId);

        $_SESSION['nimbus_uid'] = $id;
        $this->auth = new Auth($this->db);
        $this->rebuildRouter();
        return $id;
    }

    /**
     * Sign in as a user whose single custom role grants exactly $capabilities —
     * for exercising capability enforcement with a precise, non-admin actor.
     *
     * @param list<string> $capabilities
     */
    protected function actingWithCapabilities(array $capabilities, ?string $email = null): int
    {
        $email ??= 'scoped-' . bin2hex(random_bytes(4)) . '@test.local';
        $id     = $this->createUser('author', $email);
        $roles  = new \Nimbus\Auth\RoleRepository($this->db);
        $roleId = $roles->create('Scoped ' . bin2hex(random_bytes(3)), $capabilities, false);
        $roles->assignToUser($id, $roleId);

        $_SESSION['nimbus_uid'] = $id;
        $this->auth = new Auth($this->db);
        $this->rebuildRouter();
        return $id;
    }

    /** Sign in with a legacy role string and NO role assignment (for the un-seeded fallback). */
    protected function actingAsLegacy(string $role): int
    {
        $id = $this->createUser($role, $role . '@legacy.test');
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
        \Nimbus\Http\Url::bind($this->router); // so directly-dispatched routes can generate URLs
    }

    // -------------------------------------------------------- fixtures

    /**
     * @param array<int,array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}> $fields
     * @param array<string,mixed> $options
     */
    protected function makeCollection(string $handle, array $fields = [], array $options = []): Collection
    {
        $repo = new CollectionRepository($this->db);
        $options = $options ?: ['kind' => 'collection', 'permissions' => ['manage' => ['editor']]];
        $id      = (new CollectionService($this->db, $repo))->create($handle, ucfirst($handle), '#', '', $options, $fields);

        // Roles (ADR 0011): mirror the shortcut — grant each manage-role this
        // collection's write capability, so a role-based user manages it exactly
        // as the legacy manage-list intended.
        $roles = new \Nimbus\Auth\RoleRepository($this->db);
        $media = \Nimbus\Auth\RoleSeeder::CONTENT_MEDIA_CAPS;
        $base  = [
            'admin'  => ['admin'],
            'editor' => array_merge(['*:read'], $media),
            'author' => array_merge(['*:read'], $media),
        ];
        foreach ($options['permissions']['manage'] ?? [] as $roleName) {
            $roleName = (string) $roleName;
            $role     = $roles->findByName($roleName);
            if ($role !== null) {
                $rid     = $role->id;
                $current = $role->capabilities;
            } else {
                $current = $base[$roleName] ?? [];
                $rid     = $roles->create($roleName, $current, true);
            }
            $roles->setCapabilities($rid, array_values(array_unique([...$current, $handle . ':write'])));
        }
        return $repo->find($id);
    }

    // ------------------------------------------------------------ asserts

    protected function assertRedirects(?Response $response, string $to, string $message = ''): void
    {
        self::assertNotNull($response, 'expected a response, got no route match');
        self::assertSame(302, $response->status, $message);
        self::assertSame($to, $response->header('Location'), $message);
    }

    /** @param non-empty-string $prefix */
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
            ['c' => $collectionId],
        )['c'];
    }
}
