<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\FieldTypes\BaseType;
use Nimbus\Plugin\Plugin;
use Nimbus\Plugin\PluginContext;
use Nimbus\Plugin\PluginDiagnostic;
use Nimbus\Plugin\PluginLoader;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------- fixtures

final class FixtureFieldType extends BaseType
{
    public function type(): string
    {
        return 'fixture';
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return '<input name="fixture">';
    }
}

final class FixturePlugin implements Plugin
{
    public static int $registrations = 0;

    public function register(PluginContext $context): void
    {
        self::$registrations++;
        $context->fieldTypes()->register(new FixtureFieldType(), 'nimbuscms.fixture');
    }
}

/** Claims a key core already owns. */
final class ConflictingPlugin implements Plugin
{
    public function register(PluginContext $context): void
    {
        $context->fieldTypes()->register(new class () extends BaseType {
            public function type(): string
            {
                return 'text';
            }

            public function renderInput(Field $field, mixed $value): string
            {
                return '';
            }
        }, 'nimbuscms.hijacker');
    }
}

final class ExplodingPlugin implements Plugin
{
    public function register(PluginContext $context): void
    {
        throw new \RuntimeException('boom');
    }
}

final class NotAPlugin
{
}

// -------------------------------------------------------------------- test

final class PluginLoaderTest extends TestCase
{
    private string $file;
    private FieldTypeRegistry $registry;

    protected function setUp(): void
    {
        $this->file     = tempnam(sys_get_temp_dir(), 'nb-installed-') ?: '';
        $this->registry = new FieldTypeRegistry();
        FixturePlugin::$registrations = 0;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * Write a Composer installed.json containing the given packages.
     *
     * @param array<string,mixed> ...$packages
     */
    private function installed(array ...$packages): string
    {
        file_put_contents($this->file, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
        return $this->file;
    }

    /**
     * @param array<string,mixed> $nimbus
     * @return array<string,mixed>
     */
    private function package(string $name, array $nimbus, string $type = 'nimbuscms-plugin'): array
    {
        return ['name' => $name, 'type' => $type, 'extra' => ['nimbus' => $nimbus]];
    }

    /**
     * @param array<string,bool> $enabled
     * @return array{0:list<PluginDiagnostic>,1:PluginLoader}
     */
    private function load(string $path, array $enabled = []): array
    {
        $loader      = new PluginLoader($path, $enabled);
        $diagnostics = $loader->load(new PluginContext($this->registry));
        return [$diagnostics, $loader];
    }

    // ------------------------------------------------------- discovery

    public function test_installed_plugin_is_discovered_and_registered(): void
    {
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        [$diagnostics, $loader] = $this->load($path);

        self::assertSame([], $diagnostics);
        self::assertSame(['nimbuscms.fixture' => 'nimbuscms/fixture'], $loader->registered());
        self::assertTrue($this->registry->has('fixture'), 'the plugin field type is in the shared registry');
        self::assertSame('nimbuscms.fixture', $this->registry->providerOf('fixture'));
    }

    public function test_plugin_registers_exactly_once(): void
    {
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        $this->load($path);

        self::assertSame(1, FixturePlugin::$registrations);
    }

    public function test_non_plugin_packages_are_ignored(): void
    {
        $path = $this->installed(
            ['name' => 'phpunit/phpunit', 'type' => 'library'],
            ['name' => 'some/project', 'type' => 'project'],
        );

        [$diagnostics, $loader] = $this->load($path);

        self::assertSame([], $diagnostics);
        self::assertSame([], $loader->registered());
    }

    public function test_a_missing_installed_json_is_not_an_error(): void
    {
        [$diagnostics, $loader] = $this->load('/nonexistent/installed.json');

        self::assertSame([], $diagnostics);
        self::assertSame([], $loader->registered());
    }

    // ------------------------------------------------------ enable/disable

    public function test_disabled_plugin_is_not_registered(): void
    {
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        [$diagnostics, $loader] = $this->load($path, ['nimbuscms.fixture' => false]);

        self::assertSame(0, FixturePlugin::$registrations);
        self::assertFalse($this->registry->has('fixture'));
        self::assertSame([], $loader->registered());
        self::assertCount(1, $diagnostics);
        self::assertSame(PluginDiagnostic::DISABLED, $diagnostics[0]->reason);
        self::assertFalse($diagnostics[0]->isFailure(), 'disabled is a choice, not a fault');
    }

    public function test_explicitly_enabled_plugin_registers(): void
    {
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        $this->load($path, ['nimbuscms.fixture' => true]);

        self::assertTrue($this->registry->has('fixture'));
    }

    // ---------------------------------------------------------- diagnostics

    public function test_malformed_manifest_produces_a_diagnostic(): void
    {
        $path = $this->installed(
            $this->package('nimbuscms/no-id', ['plugin' => FixturePlugin::class]),
            $this->package('nimbuscms/no-class', ['id' => 'nimbuscms.noclass']),
            ['name' => 'nimbuscms/no-extra', 'type' => 'nimbuscms-plugin'],
        );

        [$diagnostics] = $this->load($path);

        self::assertCount(3, $diagnostics);
        foreach ($diagnostics as $d) {
            self::assertSame(PluginDiagnostic::INVALID_MANIFEST, $d->reason);
            self::assertTrue($d->isFailure());
        }
    }

    public function test_missing_class_produces_a_diagnostic(): void
    {
        $path = $this->installed($this->package('nimbuscms/ghost', [
            'id' => 'nimbuscms.ghost', 'plugin' => 'Nowhere\\NoSuchPlugin',
        ]));

        [$diagnostics] = $this->load($path);

        self::assertCount(1, $diagnostics);
        self::assertSame(PluginDiagnostic::MISSING_CLASS, $diagnostics[0]->reason);
        self::assertStringContainsString('autoloaded', $diagnostics[0]->message);
    }

    public function test_class_not_implementing_plugin_is_rejected(): void
    {
        $path = $this->installed($this->package('nimbuscms/impostor', [
            'id' => 'nimbuscms.impostor', 'plugin' => NotAPlugin::class,
        ]));

        [$diagnostics] = $this->load($path);

        self::assertSame(PluginDiagnostic::NOT_A_PLUGIN, $diagnostics[0]->reason);
    }

    public function test_duplicate_plugin_ids_fail(): void
    {
        $path = $this->installed(
            $this->package('nimbuscms/first', ['id' => 'nimbuscms.same', 'plugin' => FixturePlugin::class]),
            $this->package('vendor/second', ['id' => 'nimbuscms.same', 'plugin' => FixturePlugin::class]),
        );

        [$diagnostics, $loader] = $this->load($path);

        self::assertCount(1, $diagnostics);
        self::assertSame(PluginDiagnostic::DUPLICATE_ID, $diagnostics[0]->reason);
        self::assertSame('vendor/second', $diagnostics[0]->package, 'first registration wins');
        self::assertSame(['nimbuscms.same' => 'nimbuscms/first'], $loader->registered());
    }

    public function test_duplicate_field_type_fails_without_hijacking_core(): void
    {
        $path = $this->installed($this->package('nimbuscms/hijacker', [
            'id' => 'nimbuscms.hijacker', 'plugin' => ConflictingPlugin::class,
        ]));

        [$diagnostics] = $this->load($path);

        self::assertCount(1, $diagnostics);
        self::assertSame(PluginDiagnostic::REGISTER_FAILED, $diagnostics[0]->reason);
        self::assertStringContainsString('already provided by core', $diagnostics[0]->message);
        // Core's type must be untouched — this is the hijack that would have
        // reinterpreted every existing text entry.
        self::assertSame('core', $this->registry->providerOf('text'));
        self::assertSame('text', $this->registry->get('text')->type());
    }

    public function test_a_throwing_plugin_is_contained_and_reported(): void
    {
        $path = $this->installed(
            $this->package('nimbuscms/exploding', ['id' => 'nimbuscms.exploding', 'plugin' => ExplodingPlugin::class]),
            $this->package('nimbuscms/fixture', ['id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class]),
        );

        [$diagnostics, $loader] = $this->load($path);

        self::assertCount(1, $diagnostics);
        self::assertSame(PluginDiagnostic::REGISTER_FAILED, $diagnostics[0]->reason);
        self::assertStringContainsString('boom', $diagnostics[0]->message);
        // One broken plugin must not stop the others, or the admin would be
        // unreachable — which is the only place to go and disable it.
        self::assertArrayHasKey('nimbuscms.fixture', $loader->registered());
        self::assertTrue($this->registry->has('fixture'));
    }

    public function test_loading_twice_does_not_double_register(): void
    {
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        $loader = new PluginLoader($path);
        $loader->load(new PluginContext($this->registry));
        // A second load against a fresh registry must behave identically.
        $second = $loader->load(new PluginContext(new FieldTypeRegistry()));

        self::assertSame([], $second, 'no duplicate-id diagnostics from stale state');
        self::assertCount(1, $loader->registered());
    }
}
