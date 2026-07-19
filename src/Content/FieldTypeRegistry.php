<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Content\FieldTypes\BooleanType;
use Nimbus\Content\FieldTypes\DateType;
use Nimbus\Content\FieldTypes\EmailType;
use Nimbus\Content\FieldTypes\NumberType;
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
        ] as $type) {
            $this->register($type);
        }
    }

    public function register(FieldType $type): void
    {
        $this->types[$type->type()] = $type;
    }

    public function get(string $type): FieldType
    {
        return $this->types[$type] ?? $this->types['text'];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
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
