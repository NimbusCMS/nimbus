<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/** The signed-in user. */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly ?string $theme = null,
        public readonly ?string $avatarUrl = null,
    ) {
    }

    public function initial(): string
    {
        $basis = $this->name !== '' ? $this->name : $this->email;
        return mb_strtoupper(mb_substr($basis, 0, 1));
    }
}
