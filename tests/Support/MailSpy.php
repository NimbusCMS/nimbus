<?php

declare(strict_types=1);

namespace Nimbus\Tests\Support;

/**
 * Captures what {@see \Nimbus\Mail\NativeMailer} hands to PHP's `mail()` — filled
 * by the namespace-function shim in `tests/native_mail_shim.php`, which intercepts
 * the mailer's unqualified `mail(...)` call so a test can observe the exact bytes
 * (e.g. the RFC 2047 encoded subject) without ever sending real mail.
 */
final class MailSpy
{
    /** @var array{to:string,subject:string,message:string,headers:string}|null */
    public static ?array $last = null;

    public static function reset(): void
    {
        self::$last = null;
    }
}
