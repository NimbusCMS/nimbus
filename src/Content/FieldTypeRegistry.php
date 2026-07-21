<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Content\FieldTypes\BooleanType;
use Nimbus\Content\FieldTypes\DateType;
use Nimbus\Content\FieldTypes\EmailType;
use Nimbus\Content\FieldTypes\MissingType;
use Nimbus\Content\FieldTypes\NumberType;
use Nimbus\Content\FieldTypes\RelationType;
use Nimbus\Content\FieldTypes\SelectType;
use Nimbus\Content\FieldTypes\TextareaType;
use Nimbus\Content\FieldTypes\TextType;
use Nimbus\Content\FieldTypes\UrlType;

/**
 * The registry of available field types. register() is the plugin seam: a
 * plugin instantiates its own FieldType and adds it here to make it available
 * everywhere (field builder, entry forms, list cells, API).
 */
final class FieldTypeRegistry
{
    /** @var array<string,FieldType> */
    private array $types = [];

    public function __construct()
    {
        foreach ([
            new TextType(),
            new TextareaType(),
            new NumberType(),
            new BooleanType(),
            new SelectType(),
            new DateType(),
            new EmailType(),
            new UrlType(),
            new RelationType(),
        ] as $type) {
            $this->register($type);
        }
    }

    public function register(FieldType $type): void
    {
        $this->types[$type->type()] = $type;
    }

    /**
     * Strict lookup for normalization, validation, persistence and API
     * serialization. Falling back to text here would silently rewrite stored
     * values through the wrong type, so an unregistered type is an error.
     *
     * @throws UnknownFieldType
     */
    public function get(string $type): FieldType
    {
        return $this->types[$type] ?? throw new UnknownFieldType($type);
    }

    /**
     * Lookup for admin display only. Unknown types render through MissingType,
     * which shows the stored value read-only and names what is missing, so a
     * deactivated plugin degrades a screen instead of breaking the admin.
     */
    public function forDisplay(string $type): FieldType
    {
        return $this->types[$type] ?? new MissingType($type);
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * Which of a collection's field types have no provider registered.
     *
     * @param array<int,Field> $fields
     * @return string[] distinct missing type keys
     */
    public function missingFor(array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!$this->has($field->type)) {
                $missing[$field->type] = true;
            }
        }
        return array_keys($missing);
    }

    /** @return array<string,string> type key => label, for the field-type picker */
    public function choices(): array
    {
        $out = [];
        foreach ($this->types as $key => $type) {
            $out[$key] = $type->label();
        }
        return $out;
    }
}
