<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\FieldTypes\MissingType;
use Nimbus\Content\UnknownFieldType;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    private FieldTypeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new FieldTypeRegistry();
    }

    public function test_registered_types_resolve(): void
    {
        self::assertSame('text', $this->registry->get('text')->type());
        self::assertSame('number', $this->registry->get('number')->type());
        self::assertTrue($this->registry->has('relation'));
        self::assertFalse($this->registry->has('nope'));
    }

    public function test_unknown_type_throws_instead_of_becoming_text(): void
    {
        $this->expectException(UnknownFieldType::class);
        $this->expectExceptionMessageMatches('/geolocation/');

        $this->registry->get('geolocation');
    }

    public function test_unknown_type_names_itself_on_the_exception(): void
    {
        try {
            $this->registry->get('geolocation');
            self::fail('expected UnknownFieldType');
        } catch (UnknownFieldType $e) {
            self::assertSame('geolocation', $e->type);
        }
    }

    public function test_display_lookup_degrades_instead_of_throwing(): void
    {
        $type = $this->registry->forDisplay('geolocation');

        self::assertInstanceOf(MissingType::class, $type);
        self::assertSame('geolocation', $type->type());
        self::assertStringContainsString('unavailable', $type->label());
    }

    public function test_display_lookup_still_prefers_a_real_type(): void
    {
        self::assertNotInstanceOf(MissingType::class, $this->registry->forDisplay('number'));
    }

    public function test_missing_type_preserves_the_stored_value(): void
    {
        $type = $this->registry->forDisplay('geolocation');

        // Nothing is coerced — the value round-trips exactly.
        self::assertSame('51.5,-0.12', $type->normalize('51.5,-0.12'));
        self::assertSame(['a' => 1], $type->normalize(['a' => 1]));
        self::assertNull($type->normalize(null));
    }

    public function test_missing_type_always_fails_validation(): void
    {
        $field = new Field('where', 'Where', 'geolocation');
        $error = $this->registry->forDisplay('geolocation')->validate($field, '51.5,-0.12');

        self::assertNotNull($error, 'a save through a missing type must be blocked');
        self::assertStringContainsString('geolocation', $error);
    }

    public function test_missing_type_shows_the_value_read_only(): void
    {
        $field = new Field('where', 'Where', 'geolocation');
        $html  = $this->registry->forDisplay('geolocation')->renderInput($field, '51.5,-0.12');

        self::assertStringContainsString('51.5,-0.12', $html, 'the admin must still see what is stored');
        self::assertStringContainsString('geolocation', $html);
        self::assertStringNotContainsString('<input', $html, 'nothing resubmittable');
    }

    public function test_missing_type_escapes_stored_values(): void
    {
        $field = new Field('where', 'Where', 'geolocation');
        $html  = $this->registry->forDisplay('geolocation')->renderInput($field, '<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_missing_for_reports_distinct_unavailable_types(): void
    {
        $fields = [
            new Field('a', 'A', 'text'),
            new Field('b', 'B', 'geolocation'),
            new Field('c', 'C', 'geolocation'),
            new Field('d', 'D', 'colorpicker'),
        ];

        self::assertSame(['geolocation', 'colorpicker'], $this->registry->missingFor($fields));
        self::assertSame([], $this->registry->missingFor([new Field('a', 'A', 'text')]));
        self::assertSame([], $this->registry->missingFor([]));
    }

    public function test_a_registered_plugin_type_closes_the_gap(): void
    {
        $this->registry->register(new class () extends \Nimbus\Content\FieldTypes\BaseType {
            public function type(): string
            {
                return 'geolocation';
            }

            public function renderInput(Field $field, mixed $value): string
            {
                return '<input name="geo">';
            }
        });

        self::assertTrue($this->registry->has('geolocation'));
        self::assertSame('geolocation', $this->registry->get('geolocation')->type());
        self::assertSame([], $this->registry->missingFor([new Field('b', 'B', 'geolocation')]));
    }
}
