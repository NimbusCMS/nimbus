<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Content\CollectionRepository;
use Nimbus\Http\Request;
use Nimbus\Mcp\SettingsToolset;
use Nimbus\Settings\Settings;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Settings\SettingsRepository;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The MCP site-settings tools. Proves the capability gates (`get_settings` needs
 * settings:read/write, `set_settings` needs settings:write — a content `*:write`
 * scope cannot reach it), the registry allow-list (an unknown key is rejected —
 * no over-posting), value validation, and that every write is audited.
 */
final class McpSettingsToolsTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function call(string $name, array $arguments, string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return json_decode($this->throughKernel($request)->body, true);
    }

    /** @return list<string> */
    private function toolNames(string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return array_column(json_decode($this->throughKernel($request)->body, true)['result']['tools'], 'name');
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function structured(array $response): array
    {
        return $response['result']['structuredContent'];
    }

    public function test_the_tools_are_gated_by_capability(): void
    {
        $content = $this->tokens->create('C', ['posts:read']);
        $reader  = $this->tokens->create('R', ['settings:read']);
        $writer  = $this->tokens->create('W', ['settings:write']);

        self::assertNotContains('get_settings', $this->toolNames($content));
        self::assertNotContains('set_settings', $this->toolNames($content));

        self::assertContains('get_settings', $this->toolNames($reader), 'settings:read sees the getter');
        self::assertNotContains('set_settings', $this->toolNames($reader), 'but not the setter');

        self::assertContains('get_settings', $this->toolNames($writer), 'settings:write also reads');
        self::assertContains('set_settings', $this->toolNames($writer));
    }

    public function test_set_then_get_round_trips(): void
    {
        $this->makeCollection('blog');
        $writer = $this->tokens->create('W', ['settings:write']);

        $this->call('set_settings', ['settings' => ['site.home' => 'blog', 'site.description' => 'Hi there.']], $writer);
        $data = $this->structured($this->call('get_settings', [], $writer))['data'];

        self::assertSame('blog', $data['site.home']);
        self::assertSame('Hi there.', $data['site.description']);
    }

    /** A1 — an unknown key is rejected by the registry allow-list; nothing is stored. */
    public function test_set_rejects_an_unknown_key(): void
    {
        $writer = $this->tokens->create('W', ['settings:write']);

        $res = $this->structured($this->call('set_settings', ['settings' => ['role' => 'admin']], $writer));

        self::assertTrue($res['error'] !== null);
        self::assertSame('invalid', $res['error']['code']);
        self::assertSame([], (new SettingsRepository($this->db))->all());
    }

    public function test_set_validates_home_and_description(): void
    {
        $writer = $this->tokens->create('W', ['settings:write']);

        $bogusHome = $this->structured($this->call('set_settings', ['settings' => ['site.home' => 'ghost']], $writer));
        self::assertSame('invalid', $bogusHome['error']['code']);

        $tooLong = $this->structured($this->call('set_settings', ['settings' => ['site.description' => str_repeat('a', 501)]], $writer));
        self::assertSame('invalid', $tooLong['error']['code']);

        self::assertSame([], (new SettingsRepository($this->db))->all(), 'no partial write on validation failure');
    }

    /** A4 — settings is management, so the content `*:write` wildcard cannot set it. */
    public function test_a_wildcard_content_token_cannot_set_settings(): void
    {
        $content = $this->tokens->create('C', ['*:write']);

        self::assertNotContains('set_settings', $this->toolNames($content));
        self::assertSame(-32602, $this->call('set_settings', ['settings' => ['site.description' => 'x']], $content)['error']['code']);
        self::assertSame([], (new SettingsRepository($this->db))->all());
    }

    public function test_set_and_get_the_site_title(): void
    {
        $writer = $this->tokens->create('W', ['settings:write']);

        $this->call('set_settings', ['settings' => ['site.title' => 'Danmat Studio']], $writer);
        $data = $this->structured($this->call('get_settings', [], $writer))['data'];

        self::assertSame('Danmat Studio', $data['site.title']);
    }

    public function test_set_validates_the_title(): void
    {
        $writer = $this->tokens->create('W', ['settings:write']);

        $blank = $this->structured($this->call('set_settings', ['settings' => ['site.title' => '  ']], $writer));
        self::assertSame('invalid', $blank['error']['code']);

        $long = $this->structured($this->call('set_settings', ['settings' => ['site.title' => str_repeat('a', 81)]], $writer));
        self::assertSame('invalid', $long['error']['code']);

        self::assertArrayNotHasKey('site.title', (new SettingsRepository($this->db))->all());
    }

    /** SUP-2 (MCP parity) — a CRLF title via set_settings is rejected, not stored (it would DoS recovery mail). */
    public function test_set_rejects_a_title_with_control_characters(): void
    {
        $writer = $this->tokens->create('W', ['settings:write']);

        // Raw JSON strings carry CRLF; trim() strips only the ends, so it would
        // survive to the mail subject without the validator's control-char check.
        $crlf = $this->structured($this->call('set_settings', ['settings' => ['site.title' => "Nimbus\r\nX"]], $writer));
        self::assertSame('invalid', $crlf['error']['code']);

        $ctrl = $this->structured($this->call('set_settings', ['settings' => ['site.title' => "Nimbus\x01"]], $writer));
        self::assertSame('invalid', $ctrl['error']['code']);

        self::assertArrayNotHasKey('site.title', (new SettingsRepository($this->db))->all());
    }

    /** Every write is audited via API_MANAGEMENT_WRITTEN (checked at the toolset). */
    public function test_set_settings_is_audited(): void
    {
        $this->makeCollection('blog');
        $events = new EventDispatcher();
        $seen   = [];
        $events->listen(CoreEvents::API_MANAGEMENT_WRITTEN, static function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });

        $registry = new SettingsRegistry(new CollectionRepository($this->db));
        $settings = new Settings(new SettingsRepository($this->db), $registry);
        $toolset  = new SettingsToolset($settings, $registry, $events);

        $toolset->call(
            'set_settings',
            ['settings' => ['site.description' => 'Audited.']],
            new TokenPrincipal(7, 'W', ['settings:write']),
            new EntryOpContext('127.0.0.1', '/mcp'),
        );

        self::assertCount(1, $seen);
        self::assertSame('settings', $seen[0]['capability']);
        self::assertSame('site.description', $seen[0]['target']);
        self::assertSame(7, $seen[0]['token_id']);
    }
}
