<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Application;
use Nimbus\Http\Request;
use Nimbus\Mcp\Guide\CoreGuide;

/**
 * Agent guidance over MCP (ADR 0013): the `initialize` `instructions` brief and
 * the `resources/list` / `resources/read` methods, driven through the real kernel
 * so they run behind the same bearer auth as the rest of the surface. The
 * security-critical properties: `resources/read` is a registry lookup (no path
 * traversal), unknown URIs get a uniform resource-not-found, only what is
 * implemented is advertised, and generic guidance is readable by any valid token.
 */
final class McpResourcesTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> the decoded JSON-RPC response
     */
    private function rpc(string $method, array $params, string $token): array
    {
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return json_decode($this->throughKernel($request)->body, true);
    }

    public function test_initialize_advertises_resources_and_instructions_only(): void
    {
        $token  = $this->tokens->create('R', ['posts:read']);
        $result = $this->rpc('initialize', [], $token)['result'];

        // instructions present and non-trivial.
        self::assertNotEmpty($result['instructions']);
        self::assertIsString($result['instructions']);

        // serverInfo.version is the real CMS version, not a hardcoded literal.
        self::assertSame('NimbusCMS', $result['serverInfo']['name']);
        self::assertSame(Application::VERSION, $result['serverInfo']['version']);

        // capabilities: exactly tools + resources; NEVER prompts/sampling.
        self::assertSame(['listChanged' => false], $result['capabilities']['tools']);
        self::assertSame(['subscribe' => false, 'listChanged' => false], $result['capabilities']['resources']);
        self::assertArrayNotHasKey('prompts', $result['capabilities']);
        self::assertArrayNotHasKey('sampling', $result['capabilities']);
    }

    public function test_resources_list_offers_the_core_guide(): void
    {
        $token = $this->tokens->create('R', ['posts:read']);
        $list  = $this->rpc('resources/list', [], $token)['result']['resources'];

        $uris = array_column($list, 'uri');
        self::assertContains(CoreGuide::CORE_URI, $uris);
        foreach ($list as $resource) {
            self::assertSame('text/markdown', $resource['mimeType']);
        }
    }

    public function test_resources_read_returns_the_core_guide_body(): void
    {
        $token    = $this->tokens->create('R', ['posts:read']);
        $result   = $this->rpc('resources/read', ['uri' => CoreGuide::CORE_URI], $token)['result'];
        $contents = $result['contents'][0];

        self::assertSame(CoreGuide::CORE_URI, $contents['uri']);
        self::assertSame('text/markdown', $contents['mimeType']);
        self::assertStringContainsString('NimbusCMS', $contents['text']);
    }

    /**
     * The core injection/traversal guard: a URI is a registry KEY, never a path.
     * Unknown, traversal, wrong-scheme and empty URIs all get the same
     * resource-not-found, and none of them can read a file off disk.
     */
    public function test_resources_read_unknown_uri_is_a_uniform_not_found(): void
    {
        $token = $this->tokens->create('R', ['posts:read']);
        $uris  = [
            'nimbus://guide/does-not-exist',
            'nimbus://guide/../../../../etc/passwd',
            'file:///etc/passwd',
            'nimbus://guide/plugin/../core',
            '../../docs/agent/core.md',
            '',
        ];
        foreach ($uris as $uri) {
            $response = $this->rpc('resources/read', ['uri' => $uri], $token);
            self::assertArrayNotHasKey('result', $response, "URI must not resolve: {$uri}");
            self::assertSame(-32002, $response['error']['code'], "URI {$uri} must be resource-not-found");
            // No file contents ever leak into the error.
            self::assertStringNotContainsString('root:', $response['error']['message']);
        }
    }

    public function test_a_guide_is_readable_by_any_authenticated_token_including_zero_scope(): void
    {
        $token  = $this->tokens->create('Z', []);
        $result = $this->rpc('resources/read', ['uri' => CoreGuide::CORE_URI], $token);
        self::assertArrayHasKey('result', $result);
        self::assertStringContainsString('NimbusCMS', $result['result']['contents'][0]['text']);
    }

    public function test_prompts_are_not_supported(): void
    {
        $token    = $this->tokens->create('R', ['posts:read']);
        $response = $this->rpc('prompts/list', [], $token);
        self::assertSame(-32601, $response['error']['code']);
    }
}
