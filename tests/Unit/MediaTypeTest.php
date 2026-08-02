<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Field;
use Nimbus\Content\FieldTypes\MediaType;
use PHPUnit\Framework\TestCase;

final class MediaTypeTest extends TestCase
{
    private MediaType $type;

    protected function setUp(): void
    {
        $this->type = new MediaType();
    }

    public function test_normalize_keeps_a_positive_id(): void
    {
        self::assertSame(7, $this->type->normalize('7'));
        self::assertSame(7, $this->type->normalize(7));
    }

    public function test_normalize_treats_empty_and_bad_input_as_none(): void
    {
        self::assertNull($this->type->normalize(''));
        self::assertNull($this->type->normalize(null));
        self::assertNull($this->type->normalize('0'));
        self::assertNull($this->type->normalize('abc'));
    }

    public function test_normalize_takes_the_first_of_an_array(): void
    {
        // A stray array (e.g. a multi-select) collapses to a single id.
        self::assertSame(3, $this->type->normalize(['3', '4']));
        self::assertNull($this->type->normalize([]));
    }

    public function test_to_api_returns_the_raw_id_or_null(): void
    {
        $field = new Field('image', 'Image', 'media');
        self::assertSame(5, $this->type->toApi($field, 5));
        self::assertNull($this->type->toApi($field, null));
    }

    public function test_cell_summarises(): void
    {
        $field = new Field('image', 'Image', 'media');
        self::assertSame('1 file', $this->type->renderCell($field, 5));
        self::assertSame('—', $this->type->renderCell($field, null));
    }
}
