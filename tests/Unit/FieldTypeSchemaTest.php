<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Field;
use Nimbus\Content\FieldTypes\BooleanType;
use Nimbus\Content\FieldTypes\DateType;
use Nimbus\Content\FieldTypes\EmailType;
use Nimbus\Content\FieldTypes\MediaType;
use Nimbus\Content\FieldTypes\NumberType;
use Nimbus\Content\FieldTypes\RelationType;
use Nimbus\Content\FieldTypes\SelectType;
use Nimbus\Content\FieldTypes\TextType;
use Nimbus\Content\FieldTypes\UrlType;
use PHPUnit\Framework\TestCase;

/** The JSON-Schema each field type reports for OpenAPI (ADR 0008). */
final class FieldTypeSchemaTest extends TestCase
{
    public function test_scalar_types_map_to_their_json_schema(): void
    {
        self::assertSame(['type' => 'string'], (new TextType())->jsonSchema(new Field('t', 'T', 'text')));
        self::assertSame(['type' => 'number'], (new NumberType())->jsonSchema(new Field('n', 'N', 'number')));
        self::assertSame(['type' => 'boolean'], (new BooleanType())->jsonSchema(new Field('b', 'B', 'boolean')));
        self::assertSame(['type' => 'string', 'format' => 'date'], (new DateType())->jsonSchema(new Field('d', 'D', 'date')));
        self::assertSame(['type' => 'string', 'format' => 'email'], (new EmailType())->jsonSchema(new Field('e', 'E', 'email')));
        self::assertSame(['type' => 'string', 'format' => 'uri'], (new UrlType())->jsonSchema(new Field('u', 'U', 'url')));
    }

    public function test_select_becomes_an_enum_from_its_choices(): void
    {
        $schema = (new SelectType())->jsonSchema(new Field('s', 'S', 'select', false, ['choices' => ['a', 'b']]));
        self::assertSame(['type' => 'string', 'enum' => ['a', 'b']], $schema);

        // No choices -> a plain string, no enum key.
        self::assertSame(['type' => 'string'], (new SelectType())->jsonSchema(new Field('s', 'S', 'select')));
    }

    public function test_relation_is_an_array_of_target_objects(): void
    {
        $schema = (new RelationType())->jsonSchema(new Field('r', 'R', 'relation'));

        self::assertSame('array', $schema['type']);
        self::assertSame(['id', 'slug', 'title'], array_keys($schema['items']['properties']));
    }

    public function test_media_is_a_nullable_object(): void
    {
        $schema = (new MediaType())->jsonSchema(new Field('m', 'M', 'media'));

        self::assertSame('object', $schema['type']);
        self::assertTrue($schema['nullable']);
        self::assertArrayHasKey('url', $schema['properties']);
    }
}
