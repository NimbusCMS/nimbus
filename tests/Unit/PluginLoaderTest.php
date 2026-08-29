<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\FieldTypes\BaseType;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Mcp\PluginToolset;
use Nimbus\Plugin\Plugin;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginContext;
use Nimbus\Plugin\PluginDiagnostic;
use Nimbus\Plugin\PluginLoader;
use Nimbus\Plugin\PluginStatus;
use Nimbus\Site\HeadContributor;
use Nimbus\Site\PageContext;
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
        $context->fieldTypes()->register(new FixtureFieldType());
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
        });
    }
}

final class ExplodingPlugin implements Plugin
{
    public function register(PluginContext $context): void
    {
        throw new \RuntimeException('boom');
    }
}

/** Registers one type successfully, then throws — the partial-state case. */
final class HalfBrokenPlugin implements Plugin
{
    public function register(PluginContext $context): void
    {
        $context->fieldTypes()->register(new FixtureFieldType());
        $context->fieldTypes()->register(new class () extends BaseType {
            public function type(): string
            {
                return 'text'; // core already owns this — throws
            }

            public function renderInput(Field $field, mixed $value): string
            {
                return '';
            }
        });
    }
}

/** Registers one of EVERY capability, then throws — to prove all six roll back. */
final class AllCapsToolset extends PluginToolset
{
    public function namespace(): string
    {
        return 'allcaps';
    }

    protected function tools(): array
    {
        return [];
    }
}

interface AllCapsPort
{
    public function ping(): string;
}

final class AllCapsService implements AllCapsPort
{
    public function ping(): string
    {
        return 'pong';
    }
}

final class AllCapabilitiesBrokenPlugin implements Plugin
{
    public function register(PluginContext $context): void
    {
        $context->fieldTypes()->register(new FixtureFieldType());
        $context->head()->register(new class () implements HeadContributor {
            public function head(PageContext $page): string
            {
                return '<meta name="allcaps">';
            }
        });
        $context->events()->listen('allcaps.event', static fn (): null => null);
        $context->migrations()->register('allcaps', ['CREATE TABLE allcaps_x (id INT)']); // plugin-owned (not nb_*, or the FU-11 lint would throw here first)
        $context->adminPages()->register('allcaps-page', 'AllCaps', '★', static fn (): string => 'x');
        $context->maintenance()->register('allcaps-task', static fn (): int => 0);
        $context->skills()->register('AllCaps guide', 'How to drive the allcaps plugin.');
        $context->capabilities()->declare('AllCaps', ['read', 'write']);
        $context->mcp()->register(new AllCapsToolset());
        $context->routes()->get('allcaps', '/ping', static fn (Request $r, array $p): Response => Response::html('pong'));
        $context->services()->provide(AllCapsPort::class, new AllCapsService());
        // Now fail: 'text' is a core type — DuplicateFieldType, which the loader
        // turns into REGISTER_FAILED + full rollback of everything above.
        $context->fieldTypes()->register(new class () extends BaseType {
            public function type(): string
            {
                return 'text';
            }

            public function renderInput(Field $field, mixed $value): string
            {
                return '';
            }
        });
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
    private function package(string $name, array $nimbus, string $type = 'nimbuscms-plugin', string $version = 'dev'): array
    {
        return ['name' => $name, 'type' => $type, 'version' => $version, 'extra' => ['nimbus' => $nimbus]];
    }

    /**
     * @param array<string,bool> $enabled
     * @return array{0:list<PluginDiagnostic>,1:PluginLoader}
     */
    private function load(string $path, array $enabled = []): array
    {
        $loader      = new PluginLoader($path, $enabled);
        $diagnostics = $loader->load(new PluginCapabilities(fieldTypes: $this->registry));
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

    public function test_a_bundle_naming_only_field_types_still_registers(): void
    {
        // The bundle defaults every other registry, so a caller (e.g. a plugin's
        // package test) constructs only the one it cares about.
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        $diagnostics = (new PluginLoader($path))->load(new PluginCapabilities(fieldTypes: $this->registry));

        self::assertSame([], $diagnostics);
        self::assertTrue($this->registry->has('fixture'));
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

    public function test_a_plugin_id_in_the_reserved_nb_namespace_is_rejected(): void
    {
        // FU-18: `nb` (and `nb_`/`nb.`/`nb-`) is core's reserved table namespace —
        // an id there would name tables `nb_*` and collide with core.
        $path = $this->installed(
            $this->package('vendor/nb-stats', ['id' => 'nb_stats', 'plugin' => FixturePlugin::class]),
            $this->package('vendor/nb', ['id' => 'nb', 'plugin' => FixturePlugin::class]),
        );

        [$diagnostics] = $this->load($path);

        self::assertCount(2, $diagnostics);
        foreach ($diagnostics as $d) {
            self::assertSame(PluginDiagnostic::INVALID_MANIFEST, $d->reason);
            self::assertStringContainsString('nb', $d->message);
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

    // -------------------------------------------------- registration safety

    public function test_a_failed_registration_leaves_nothing_behind(): void
    {
        $path = $this->installed($this->package('nimbuscms/halfbroken', [
            'id' => 'nimbuscms.halfbroken', 'plugin' => HalfBrokenPlugin::class,
        ]));

        [$diagnostics, $loader] = $this->load($path);

        self::assertCount(1, $diagnostics);
        self::assertSame(PluginDiagnostic::REGISTER_FAILED, $diagnostics[0]->reason);
        // The first type landed before the throw; it must not survive, or the
        // diagnostics would say "failed" while the app is half-running it.
        self::assertFalse($this->registry->has('fixture'), 'partial registration must be rolled back');
        self::assertSame([], $loader->registered());
        self::assertStringContainsString('Rolled back: fixture', $diagnostics[0]->message);
    }

    public function test_a_failed_registration_rolls_back_every_capability(): void
    {
        // A full bundle whose registries we can inspect after the failed load.
        $caps   = new PluginCapabilities();
        $path   = $this->installed($this->package('nimbuscms/allcaps', [
            'id' => 'nimbuscms.allcaps', 'plugin' => AllCapabilitiesBrokenPlugin::class,
        ]));
        $loader = new PluginLoader($path, []);
        $diagnostics = $loader->load($caps);

        self::assertSame(PluginDiagnostic::REGISTER_FAILED, $diagnostics[0]->reason);

        // Every registry must be empty for the failed provider — not just field types.
        $page = new PageContext('home', 'https://example.test/', 'X', 'S', 'AAAAAAAAAAAAAAAAAAAAAA==');
        $covered = [
            'fieldTypes'  => fn (): bool => !$caps->fieldTypes->has('fixture'),
            'head'        => fn (): bool => $caps->head->render($page) === '',
            'events'      => fn (): bool => !$caps->events->hasListeners('allcaps.event'),
            'migrations'  => fn (): bool => $caps->migrations->all() === [],
            'adminPages'  => fn (): bool => $caps->adminPages->all() === [],
            'maintenance'  => fn (): bool => $caps->maintenance->all() === [],
            'skills'       => fn (): bool => $caps->skills->documents() === [],
            'capabilities' => fn (): bool => $caps->capabilities->managementResources() === [],
            'mcpToolsets'  => fn (): bool => $caps->mcpToolsets->all() === [],
            'routes'       => fn (): bool => $caps->routes->all() === [],
            'services'     => fn (): bool => $caps->services->get(AllCapsPort::class) === null,
        ];
        foreach ($covered as $name => $isClean) {
            self::assertTrue($isClean(), "the {$name} registry was not rolled back on a failed load");
        }

        // Completeness tripwire: every public registry prop on the bundle must be
        // covered above — a NEW capability fails this until its rollback is added
        // to the loader's catch AND asserted here (the bundle-refactor lesson).
        $skip = ['db']; // a Connection, not a registry
        foreach ((new \ReflectionObject($caps))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $n = $prop->getName();
            self::assertTrue(
                isset($covered[$n]) || in_array($n, $skip, true),
                "PluginCapabilities::\${$n} is not covered by the rollback test — add its rollback to PluginLoader's catch and an assertion here.",
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedIds')]
    public function test_a_reserved_or_malformed_plugin_id_is_rejected(string $badId): void
    {
        $path = $this->installed($this->package('nimbuscms/x', ['id' => $badId, 'plugin' => FixturePlugin::class]));
        [$diagnostics, $loader] = $this->load($path);

        self::assertNotEmpty($diagnostics);
        self::assertSame(PluginDiagnostic::INVALID_MANIFEST, $diagnostics[0]->reason, "id \"{$badId}\" must be INVALID_MANIFEST");
        self::assertSame([], $loader->registered(), "id \"{$badId}\" must not register anything");
    }

    /** @return array<string,array{string}> */
    public static function malformedIds(): array
    {
        return [
            'reserved core'          => ['core'],
            'empty'                  => [''],
            'colon'                  => ['a:b'],
            'uppercase'              => ['Bad'],
            'leading dot'            => ['.hidden'],
            'over 64 chars'          => [str_repeat('x', 65)],
            // ADR 0014: an id rooted in a core event namespace could forge a core
            // event (emit prefixes the id verbatim), so the loader rejects it.
            'reserved event root'    => ['entry'],
            'reserved root api'      => ['api'],
            'reserved root auth'     => ['auth'],
            'reserved root request'  => ['request'],
            'rooted in a core event' => ['entry.stats'],
        ];
    }

    public function test_a_plugin_claiming_id_core_cannot_defeat_field_type_rollback(): void
    {
        // id=core is rejected at validation, so the plugin never registers — core's
        // own types are untouched and the plugin's type never lands (the containment
        // invariant FieldTypeRegistry::forgetProvider('core') alone could not hold).
        $path = $this->installed($this->package('nimbuscms/evil', [
            'id' => 'core', 'plugin' => HalfBrokenPlugin::class,
        ]));
        [$diagnostics] = $this->load($path);

        self::assertSame(PluginDiagnostic::INVALID_MANIFEST, $diagnostics[0]->reason);
        self::assertSame('core', $this->registry->providerOf('text'), 'core types are untouched');
        self::assertFalse($this->registry->has('fixture'), 'the rejected plugin registered nothing');
    }

    public function test_rollback_never_touches_core_types(): void
    {
        $path = $this->installed($this->package('nimbuscms/halfbroken', [
            'id' => 'nimbuscms.halfbroken', 'plugin' => HalfBrokenPlugin::class,
        ]));

        $this->load($path);

        foreach (['text', 'textarea', 'number', 'boolean', 'select', 'date', 'email', 'url', 'relation'] as $core) {
            self::assertTrue($this->registry->has($core), "core type {$core} must survive a plugin failure");
            self::assertSame('core', $this->registry->providerOf($core));
        }
    }

    public function test_a_plugin_cannot_claim_to_be_another_provider(): void
    {
        // The loader binds the provider id, so a plugin cannot register under
        // "core" and then have core's types rolled back when it fails.
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        $this->load($path);

        self::assertSame('nimbuscms.fixture', $this->registry->providerOf('fixture'));
    }

    // ------------------------------------------------- ids claimed on install

    public function test_a_disabled_plugin_still_holds_its_id(): void
    {
        // Otherwise disabling the official plugin would silently hand its
        // identity to any other installed package claiming the same id.
        $path = $this->installed(
            $this->package('official/thing', ['id' => 'shared.id', 'plugin' => FixturePlugin::class]),
            $this->package('squatter/thing', ['id' => 'shared.id', 'plugin' => FixturePlugin::class]),
        );

        [$diagnostics, $loader] = $this->load($path, ['shared.id' => false]);

        self::assertSame([], $loader->registered(), 'the disabled plugin is off, and nobody inherits its id');
        self::assertFalse($this->registry->has('fixture'));

        $reasons = array_map(static fn (PluginDiagnostic $d): string => $d->reason, $diagnostics);
        self::assertContains(PluginDiagnostic::DUPLICATE_ID, $reasons);
        self::assertContains(PluginDiagnostic::DISABLED, $reasons);
    }

    public function test_a_broken_plugin_still_holds_its_id(): void
    {
        $path = $this->installed(
            $this->package('official/thing', ['id' => 'shared.id', 'plugin' => 'Nowhere\\Missing']),
            $this->package('squatter/thing', ['id' => 'shared.id', 'plugin' => FixturePlugin::class]),
        );

        [$diagnostics, $loader] = $this->load($path);

        self::assertSame([], $loader->registered());
        self::assertFalse($this->registry->has('fixture'), 'a broken owner must not hand its id to another package');
        self::assertCount(2, $diagnostics);
    }

    // -------------------------------------------------------------- statuses

    public function test_statuses_report_a_healthy_plugin(): void
    {
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class, 'name' => 'Fixture',
        ], version: '1.2.3'));

        [, $loader] = $this->load($path);
        $statuses   = $loader->statuses();

        self::assertCount(1, $statuses);
        $s = $statuses[0];
        self::assertSame('nimbuscms.fixture', $s->id);
        self::assertSame('nimbuscms/fixture', $s->packageName);
        self::assertSame('Fixture', $s->displayName);
        self::assertSame('1.2.3', $s->version);
        self::assertTrue($s->enabled);
        self::assertSame(PluginStatus::HEALTHY, $s->state);
        self::assertTrue($s->official, 'the nimbuscms/ vendor is official');
        self::assertFalse($s->isProblem());
    }

    public function test_statuses_cover_every_discovered_package(): void
    {
        $path = $this->installed(
            $this->package('nimbuscms/ok', ['id' => 'a', 'plugin' => FixturePlugin::class]),
            $this->package('vendor/off', ['id' => 'b', 'plugin' => FixturePlugin::class]),
            $this->package('vendor/broken', ['id' => 'c', 'plugin' => ExplodingPlugin::class]),
            $this->package('vendor/no-class', ['id' => 'd', 'plugin' => 'Nowhere\\X']),
        );

        [, $loader] = $this->load($path, ['b' => false]);
        $byId       = [];
        foreach ($loader->statuses() as $s) {
            $byId[$s->id] = $s;
        }

        self::assertSame(PluginStatus::HEALTHY, $byId['a']->state);
        self::assertSame(PluginStatus::DISABLED, $byId['b']->state);
        self::assertFalse($byId['b']->enabled);
        self::assertSame(PluginStatus::FAILED, $byId['c']->state);
        self::assertStringContainsString('boom', $byId['c']->message);
        self::assertSame(PluginStatus::INVALID, $byId['d']->state);
        self::assertTrue($byId['c']->isProblem());
        self::assertTrue($byId['d']->isProblem());
        self::assertFalse($byId['b']->official, 'the vendor/ prefix is community');
    }

    public function test_status_display_name_falls_back_to_a_humanised_package(): void
    {
        $path = $this->installed($this->package('acme/plugin-photo-gallery', [
            'id' => 'acme.gallery', 'plugin' => FixturePlugin::class,
        ]));

        [, $loader] = $this->load($path);

        self::assertSame('Plugin Photo Gallery', $loader->statuses()[0]->displayName);
    }

    public function test_status_messages_never_carry_a_class_name(): void
    {
        // The UI message stays human; the class name lives only in the log line.
        $path = $this->installed($this->package('vendor/broken', [
            'id' => 'nimbuscms.broken', 'plugin' => ExplodingPlugin::class,
        ]));

        [$diagnostics, $loader] = $this->load($path);

        self::assertStringContainsString('RuntimeException', $diagnostics[0]->message);
        self::assertStringNotContainsString('RuntimeException', $loader->statuses()[0]->message);
    }

    public function test_loading_twice_does_not_double_register(): void
    {
        $path = $this->installed($this->package('nimbuscms/fixture', [
            'id' => 'nimbuscms.fixture', 'plugin' => FixturePlugin::class,
        ]));

        $loader = new PluginLoader($path);
        $loader->load(new PluginCapabilities(fieldTypes: $this->registry));
        // A second load against a fresh registry must behave identically.
        $second = $loader->load(new PluginCapabilities());

        self::assertSame([], $second, 'no duplicate-id diagnostics from stale state');
        self::assertCount(1, $loader->registered());
    }
}
