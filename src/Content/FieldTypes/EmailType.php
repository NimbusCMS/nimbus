<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

class EmailType extends TextType
{
    public function type(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    protected function htmlType(): string
    {
        return 'email';
    }
}
