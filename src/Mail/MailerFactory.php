<?php

declare(strict_types=1);

namespace Nimbus\Mail;

use Nimbus\Support\Config;

/**
 * Builds the configured {@see Mailer} — the one place transport selection lives.
 * Unknown/misconfigured transports fall back to {@see LogMailer} so a typo in
 * `MAIL_TRANSPORT` can never silently drop mail on the floor with no trace.
 */
final class MailerFactory
{
    public static function fromConfig(): Mailer
    {
        return match (Config::mailTransport()) {
            'native' => new NativeMailer(Config::mailFrom()),
            'api'    => new ApiMailer(Config::mailApiEndpoint(), Config::mailApiKey(), Config::mailFrom()),
            default  => new LogMailer(Config::mailLogPath()),
        };
    }
}
