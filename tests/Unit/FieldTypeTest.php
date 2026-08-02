<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\FieldTypes\BaseType;
use Nimbus\Content\FieldTypes\DateType;
use Nimbus\Content\FieldTypes\NumberType;
use Nimbus\Content\FieldTypes\RelationType;
use Nimbus\Content\FieldTypes\SelectType;
use Nimbus\Content\FieldTypes\TextType;
use PHPUnit\Framework\TestCase;

final class FieldTypeTest extends TestCase
{
    public function test_optional_invalid_number_is_preserved_then_rejected(): void
    {
        $type  = new NumberType();
        $field = new Field('n', 'N', 'number');

        self::assertSame('abc', $type->normalize('abc'), 'invalid input must not be blanked');
        self::assertNotNull($type->validate($field, 'abc'));
        self::assertNull($type->normalize(''));
        self::assertSame(12, $type->normalize('12'));
        self::assertSame(3.5, $type->normalize('3.5'));
        self::assertNull($type->validate($field, 12));
    }

    public function test_invalid_select_value_is_rejected(): void
    {
        $type  = new SelectType();
        $field = new Field('c', 'C', 'select', false, ['choices' => ['red', 'green']]);

        self::assertNull($type->validate($field, 'red'));
        self::assertNotNull($type->validate($field, 'purple'));
    }

    public function test_impossible_date_is_rejected(): void
    {
        $type  = new DateType();
        $field = new Field('d', 'D', 'date');

        self::assertNotNull($type->validate($field, '2026-02-31'));
        self::assertNull($type->validate($field, '2026-02-28'));
    }

    public function test_to_api_returns_expected_types(): void
    {
        self::assertSame('hello', (new TextType())->toApi(new Field('t', 'T', 'text'), 'hello'));

        $relation = new RelationType();
        $relField = new Field('r', 'R', 'relation', false, [], 5);
        self::assertSame([1, 2], $relation->toApi($relField, [1, 2]));
        self::assertSame([], $relation->toApi($relField, null));
    }

    public function test_custom_field_type_registers_without_core_changes(): void
    {
        $registry = new FieldTypeRegistry();
        $custom = new class () extends BaseType {
            public function type(): string
            {
                return 'stars';
            }

            public function renderInput(Field $field, mixed $value): string
            {
                return '<input type="number">';
            }
        };

        $registry->register($custom);

        self::assertTrue($registry->has('stars'));
        self::assertSame($custom, $registry->get('stars'));
        self::assertArrayHasKey('stars', $registry->choices());
    }

    public function test_rendering_escapes_value_and_attributes(): void
    {
        $type = new TextType();

        $html = $type->renderInput(new Field('t', 'T', 'text'), '"><script>alert(1)</script>');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);

        $withPlaceholder = $type->renderInput(new Field('t', 'T', 'text', false, ['placeholder' => '"evil"']), '');
        self::assertStringContainsString('placeholder="&quot;evil&quot;"', $withPlaceholder);
    }
}
