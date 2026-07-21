<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\Collection;
use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\FieldTypes\NumberType;
use Nimbus\Content\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Numbers go through normalize() before validate(), so a bad submission has to
 * survive normalization for validation to have anything to reject. These run
 * against the same registry + Validator the HTTP write path uses.
 */
final class NumberTypeTest extends TestCase
{
    private NumberType $type;
    private Validator $validator;

    protected function setUp(): void
    {
        $this->type      = new NumberType();
        $this->validator = new Validator(new FieldTypeRegistry());
    }

    private function collection(bool $required): Collection
    {
        return new Collection(
            1,
            'products',
            'Products',
            '#',
            '',
            [new Field('qty', 'Qty', 'number', $required)],
            ['kind' => 'collection'],
        );
    }

    /** Mirror the controller: normalize the raw submission, then validate. */
    private function submit(mixed $raw, bool $required = false): array
    {
        return $this->validator->validate(
            $this->collection($required),
            ['qty' => $this->type->normalize($raw)],
        );
    }

    // ------------------------------------------------------- normalization

    public function test_integer_input_becomes_int(): void
    {
        self::assertSame(42, $this->type->normalize('42'));
        self::assertIsInt($this->type->normalize('42'));
    }

    public function test_decimal_input_becomes_float(): void
    {
        self::assertSame(3.5, $this->type->normalize('3.5'));
        self::assertIsFloat($this->type->normalize('3.5'));
    }

    public function test_negative_and_zero(): void
    {
        self::assertSame(-7, $this->type->normalize('-7'));
        self::assertSame(-2.5, $this->type->normalize('-2.5'));
    }

    public function test_zero_string_becomes_integer_zero_and_is_not_empty(): void
    {
        $normalized = $this->type->normalize('0');

        self::assertSame(0, $normalized);
        self::assertIsInt($normalized);
        // The regression this guards: 0 must not be mistaken for a blank field.
        self::assertSame([], $this->submit('0', required: true), '"0" must satisfy a required number');
    }

    public function test_blank_normalizes_to_null(): void
    {
        self::assertNull($this->type->normalize(''));
        self::assertNull($this->type->normalize(null));
    }

    public function test_invalid_input_survives_normalization(): void
    {
        // If this were coerced to null or 0, validation would have nothing to
        // reject and the user's typo would be silently swallowed.
        self::assertSame('abc', $this->type->normalize('abc'));
        self::assertSame('12abc', $this->type->normalize('12abc'));
    }

    // ---------------------------------------------------------- validation

    public function test_optional_blank_number_passes(): void
    {
        self::assertSame([], $this->submit('', required: false));
    }

    public function test_optional_invalid_number_fails(): void
    {
        $errors = $this->submit('abc', required: false);

        self::assertArrayHasKey('qty', $errors);
        self::assertStringContainsString('valid number', $errors['qty']);
    }

    public function test_required_blank_number_fails(): void
    {
        $errors = $this->submit('', required: true);

        self::assertArrayHasKey('qty', $errors);
        self::assertStringContainsString('required', $errors['qty']);
    }

    public function test_required_valid_number_passes(): void
    {
        self::assertSame([], $this->submit('42', required: true));
        self::assertSame([], $this->submit('3.5', required: true));
    }

    public function test_required_invalid_number_fails(): void
    {
        self::assertArrayHasKey('qty', $this->submit('abc', required: true));
    }
}
