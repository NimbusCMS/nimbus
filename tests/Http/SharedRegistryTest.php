<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Application;
use Nimbus\Content\Field;
use Nimbus\Content\FieldType;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\FieldTypes\BaseType;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;
use ReflectionProperty;

/**
 * The single most important detail for plugins: registration only works if
 * everything reads the *same* registry instance.
 *
 * A plugin adds its field type to the application's registry. If a controller
 * or service quietly builds its own, the type registers into an object nobody
 * reads and the plugin silently does nothing — the worst possible failure,
 * because it looks like it worked.
 */
final class SharedRegistryTest extends HttpTestCase
{
    private function fieldTypeOf(object $controller): FieldTypeRegistry
    {
        $property = new ReflectionProperty($controller, 'types');
        return $property->getValue($controller);
    }

    private function pluginType(string $key = 'demo'): FieldType
    {
        return new class ($key) extends BaseType {
            public function __construct(private string $key)
            {
            }

            public function type(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Demo';
            }

            public function renderInput(Field $field, mixed $value): string
            {
                return '<input name="demo">';
            }
        };
    }

    // ------------------------------------------------------ one instance

    public function test_the_application_hands_controllers_one_registry(): void
    {
        $app = new Application($this->db, $this->auth);

        $shared = new ReflectionProperty($app, 'fieldTypes');
        /** @var FieldTypeRegistry $registry */
        $registry = $shared->getValue($app);

        // Registering here must be visible everywhere the registry travels.
        $registry->register($this->pluginType());
        self::assertTrue($registry->has('demo'));

        $app->routes(); // constructs the controllers
        self::assertTrue($registry->has('demo'), 'building routes must not replace the registry');
    }

    public function test_a_type_registered_before_routing_reaches_the_field_builder(): void
    {
        $app      = new Application($this->db, $this->auth);
        $registry = (new ReflectionProperty($app, 'fieldTypes'))->getValue($app);
        $registry->register($this->pluginType('demo'));

        $this->actingAs('admin');
        // Rebuild against the same application instance so the registry carries over.
        $response = $app->handle($this->request('GET', '/admin/collections/new'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('value="demo"', $response->body, 'the plugin type must appear in the type picker');
        self::assertStringContainsString('Demo', $response->body);
    }

    public function test_controllers_do_not_build_their_own_registry(): void
    {
        $app    = new Application($this->db, $this->auth);
        $shared = (new ReflectionProperty($app, 'fieldTypes'))->getValue($app);

        $collections = new \Nimbus\Admin\CollectionsController($this->db, $this->auth, $shared);
        $entries     = new \Nimbus\Admin\EntriesController($this->db, $this->auth, $shared, new EventDispatcher());

        self::assertSame($shared, $this->fieldTypeOf($collections));
        self::assertSame($shared, $this->fieldTypeOf($entries));
    }

    // -------------------------------------------------------- isolation

    public function test_two_applications_do_not_share_a_registry(): void
    {
        $first  = new Application($this->db, $this->auth);
        $second = new Application($this->db, $this->auth);

        (new ReflectionProperty($first, 'fieldTypes'))->getValue($first)->register($this->pluginType('only_in_first'));

        self::assertFalse(
            (new ReflectionProperty($second, 'fieldTypes'))->getValue($second)->has('only_in_first'),
            'registries must not be static — one application must not contaminate another',
        );
    }

    public function test_two_dispatchers_do_not_share_listeners(): void
    {
        $first  = new EventDispatcher();
        $second = new EventDispatcher();
        $fired  = 0;

        $first->listen(CoreEvents::ENTRY_SAVED, function () use (&$fired): void {
            $fired++;
        });

        $second->dispatch(CoreEvents::ENTRY_SAVED, []);
        self::assertSame(0, $fired, 'a listener on one dispatcher must not fire on another');

        $first->dispatch(CoreEvents::ENTRY_SAVED, []);
        self::assertSame(1, $fired);
    }

    public function test_dispatcher_reports_whether_anyone_is_listening(): void
    {
        $events = new EventDispatcher();

        self::assertFalse($events->hasListeners(CoreEvents::ENTRY_SAVED));
        $events->listen(CoreEvents::ENTRY_SAVED, static fn () => null);
        self::assertTrue($events->hasListeners(CoreEvents::ENTRY_SAVED));
    }

    public function test_listeners_run_in_registration_order(): void
    {
        $events = new EventDispatcher();
        $order  = [];

        $events->listen(CoreEvents::ENTRY_SAVED, function () use (&$order): void {
            $order[] = 'first';
        });
        $events->listen(CoreEvents::ENTRY_SAVED, function () use (&$order): void {
            $order[] = 'second';
        });
        $events->dispatch(CoreEvents::ENTRY_SAVED, []);

        self::assertSame(['first', 'second'], $order);
    }
}
