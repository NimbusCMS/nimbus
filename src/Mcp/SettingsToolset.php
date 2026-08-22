<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Settings\Settings;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The MCP site-settings tools (ADR 0009 family): `get_settings` and
 * `set_settings`, over the same typed {@see SettingsRegistry} the admin form and
 * the {@see Settings} service use, so the surfaces cannot drift.
 *
 * Authorization mirrors the management model: `get_settings` needs
 * `settings:read` (or `settings:write`/`admin`) and `set_settings` needs
 * `settings:write` — a management capability, so a content `*:write` scope can
 * never reach it. Writes go through the registry allow-list: an unregistered key
 * is rejected (no over-posting), every value is registry-validated before it is
 * stored, and each write is audited via {@see CoreEvents::API_MANAGEMENT_WRITTEN}.
 * Discovery stays non-enumerating: a tool the token may not use is hidden from
 * `tools/list` and reported as an unknown tool on call.
 */
final class SettingsToolset implements Toolset
{
    private const CAPABILITY = 'settings';
    /** @var list<string> */
    private const TOOLS = ['get_settings', 'set_settings'];

    public function __construct(
        private Settings $settings,
        private SettingsRegistry $registry,
        private EventDispatcher $events,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(TokenPrincipal $principal): array
    {
        $defs = [];
        if ($this->canRead($principal)) {
            $defs[] = $this->getDefinition();
        }
        if ($principal->can(self::CAPABILITY, 'write')) {
            $defs[] = $this->setDefinition();
        }
        return $defs;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function call(string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): ?array
    {
        if (!in_array($name, self::TOOLS, true)) {
            return null;
        }

        $action    = $name === 'get_settings' ? 'read' : 'write';
        $permitted = $name === 'get_settings' ? $this->canRead($principal) : $principal->can(self::CAPABILITY, 'write');
        if (!$permitted) {
            $this->events->emitBestEffort(CoreEvents::API_ACCESS_DENIED, [
                'token_id' => $principal->tokenId, 'token_name' => $principal->name,
                'resource' => self::CAPABILITY, 'action' => $action,
                'ip' => $ctx->ip, 'path' => $ctx->path, 'at' => date('Y-m-d H:i:s'),
            ]);
            throw new McpError(JsonRpc::INVALID_PARAMS, "Unknown tool \"{$name}\".");
        }

        return $name === 'get_settings'
            ? $this->getSettings()
            : $this->setSettings($args, $principal, $ctx);
    }

    /** @return array<string,mixed> */
    private function getSettings(): array
    {
        return ToolResult::ok(['data' => $this->current()]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function setSettings(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $submitted = is_array($args['settings'] ?? null) ? $args['settings'] : null;
        if ($submitted === null || $submitted === []) {
            return ToolResult::error('Provide a "settings" object, e.g. {"site.description":"…"}.', 'invalid');
        }

        // Validate every submitted key against the registry BEFORE storing any, so
        // one bad value leaves the store untouched. An unregistered key is
        // rejected outright — the allow-list, not free key/value.
        $values = [];
        foreach ($submitted as $key => $value) {
            if (!is_string($key)) {
                return ToolResult::error('Setting keys must be strings.', 'invalid');
            }
            $setting = $this->registry->find($key);
            if ($setting === null) {
                return ToolResult::error("Unknown setting \"{$key}\".", 'invalid');
            }
            if (!is_string($value)) {
                return ToolResult::error("Setting \"{$key}\" must be a string.", 'invalid', [$key => 'expected a string']);
            }
            $value = trim($value);
            $error = $setting->validate($value);
            if ($error !== null) {
                return ToolResult::error($error, 'invalid', [$key => $error]);
            }
            $values[$key] = $value;
        }

        foreach ($values as $key => $value) {
            $this->settings->set($key, $value);
            $this->events->emitBestEffort(CoreEvents::API_MANAGEMENT_WRITTEN, [
                'token_id' => $principal->tokenId, 'token_name' => $principal->name,
                'capability' => self::CAPABILITY, 'action' => 'set', 'target' => $key,
                'ip' => $ctx->ip, 'path' => $ctx->path, 'at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ToolResult::ok(['updated' => array_keys($values), 'settings' => $this->current()]);
    }

    /** @return array<string,string> the known settings, key => current value */
    private function current(): array
    {
        $out = [];
        foreach (array_keys($this->registry->all()) as $key) {
            $out[$key] = $this->settings->get($key);
        }
        return $out;
    }

    private function canRead(TokenPrincipal $principal): bool
    {
        return $principal->can(self::CAPABILITY, 'read') || $principal->can(self::CAPABILITY, 'write');
    }

    /** @return array<string,mixed> */
    private function getDefinition(): array
    {
        return $this->tool('get_settings', 'Read the site settings (home page, description) and their current values.', ['type' => 'object', 'properties' => new \stdClass()]);
    }

    /** @return array<string,mixed> */
    private function setDefinition(): array
    {
        return $this->tool('set_settings', 'Set one or more site settings. Only known keys are accepted; values are validated. e.g. {"settings":{"site.description":"A small, modern CMS."}}.', [
            'type'       => 'object',
            'required'   => ['settings'],
            'properties' => [
                'settings' => [
                    'type'        => 'object',
                    'description' => 'A map of setting key => string value. Known keys: ' . implode(', ', array_keys($this->registry->all())) . '.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $inputSchema
     * @return array<string,mixed>
     */
    private function tool(string $name, string $description, array $inputSchema): array
    {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $inputSchema];
    }
}
