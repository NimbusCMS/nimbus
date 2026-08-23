<?php

declare(strict_types=1);

/**
 * Test-only shim: define `Nimbus\Mail\mail()` so `NativeMailer`'s unqualified
 * `@mail(...)` resolves to this instead of the global built-in. It records the
 * arguments into {@see \Nimbus\Tests\Support\MailSpy} and reports success — so a
 * test observes exactly what would reach the MTA (the encoded subject, the
 * headers) and no real mail is ever sent from the suite. Production code is
 * untouched; PHP resolves the namespaced function at call time.
 */

namespace Nimbus\Mail;

use Nimbus\Tests\Support\MailSpy;

if (!function_exists(__NAMESPACE__ . '\\mail')) {
    function mail(string $to, string $subject, string $message, string $headers = '', string $params = ''): bool
    {
        MailSpy::$last = ['to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers];
        return true;
    }
}
