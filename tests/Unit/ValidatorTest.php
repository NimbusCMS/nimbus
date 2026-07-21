<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Collection;
use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /** @param array<int,\Nimbus\Content\Field> $fields */
    private function collection(array $fields): Collection
    {
        return new Collection(1, 'c', 'C', '#', '', $fields, []);
    }

    private function validator(): Validator
    {
        return new Validator(new FieldTypeRegistry());
    }

    public function test_required_boolean_false_is_not_treated_as_absent(): void
    {
        $c = $this->collection([new Field('flag', 'Flag', 'boolean', true)]);
        self::assertSame([], $this->validator()->validate($c, ['flag' => 0]));
    }

    public function test_zero_is_not_considered_empty(): void
    {
        $numeric = $this->collection([new Field('n', 'N', 'number', true)]);
        self::assertSame([], $this->validator()->validate($numeric, ['n' => 0]));

        $string = $this->collection([new Field('t', 'T', 'text', true)]);
        self::assertSame([], $this->validator()->validate($string, ['t' => '0']));
    }

    public function test_required_empty_field_is_rejected(): void
    {
        $c = $this->collection([new Field('t', 'T', 'text', true)]);
        self::assertArrayHasKey('t', $this->validator()->validate($c, ['t' => '']));
    }

    public function test_optional_invalid_number_is_rejected(): void
    {
        $c = $this->collection([new Field('n', 'N', 'number', false)]);
        self::assertArrayHasKey('n', $this->validator()->validate($c, ['n' => 'abc']));
    }
}
