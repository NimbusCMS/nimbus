<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Mcp\Guide\CoreGuide;
use Nimbus\Mcp\MediaToolset;
use Nimbus\Mcp\SchemaToolset;
use Nimbus\Mcp\SettingsToolset;
use Nimbus\Mcp\TokensToolset;
use Nimbus\Mcp\UsersToolset;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Drift guard for ADR 0013's promise that the agent guide "truly covers all core
 * functionality." Every fixed management tool name (read by reflection from the
 * toolsets, so a NEW tool forces a guide update) and every cross-cutting error
 * code must appear in the core guide. This makes it impossible to ship a toolset
 * the guide never mentions.
 */
final class AgentGuideCoverageTest extends TestCase
{
    private static function coreGuide(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/docs/agent/core.md');
    }

    /**
     * Every `*TOOLS` constant across the management toolsets, flattened.
     *
     * @return list<string>
     */
    private static function managementToolNames(): array
    {
        $names = ['list_collections']; // the one fixed content-discovery tool
        foreach ([SchemaToolset::class, MediaToolset::class, UsersToolset::class, TokensToolset::class, SettingsToolset::class] as $class) {
            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                if (str_contains($name, 'TOOLS') && is_array($value)) {
                    foreach ($value as $tool) {
                        $names[] = (string) $tool;
                    }
                }
            }
        }
        return array_values(array_unique($names));
    }

    public function test_every_management_tool_name_appears_in_the_core_guide(): void
    {
        $guide = self::coreGuide();
        foreach (self::managementToolNames() as $tool) {
            self::assertStringContainsString($tool, $guide, "The agent guide must document the tool `{$tool}`.");
        }
    }

    public function test_the_content_verb_pattern_is_documented(): void
    {
        $guide = self::coreGuide();
        foreach (['list_H', 'get_H', 'create_H', 'update_H', 'delete_H'] as $verb) {
            self::assertStringContainsString($verb, $guide);
        }
    }

    public function test_every_api_error_code_appears_in_the_core_guide(): void
    {
        $guide = self::coreGuide();
        $codes = ['unauthorized', 'forbidden', 'not_found', 'invalid', 'missing_provider', 'precondition_required', 'precondition_failed', 'rate_limited'];
        foreach ($codes as $code) {
            self::assertStringContainsString($code, $guide, "The agent guide must document the `{$code}` error code.");
        }
    }

    public function test_the_instructions_brief_stays_within_the_always_in_context_cap(): void
    {
        $instructions = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/agent/instructions.md');
        self::assertNotEmpty($instructions);
        self::assertLessThanOrEqual(CoreGuide::INSTRUCTIONS_MAX_BYTES, strlen($instructions));
    }
}
