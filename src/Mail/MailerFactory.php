<?php

declare(strict_types=1);

namespace Nimbus\Mail;

use Nimbus\Support\Config;

/**
 * Builds the configured {@see Mailer} — the one place transport selection lives.
 * Unknown/misconfigured transports fall back to {@see LogMailer} so a typo in
 * `MAIL_TRANSPORT` can never silently drop mail on the floor with no trace — and
 * that fallback is now **loud** (the LogMailer warns on every send; SUP-1).
 */
final class MailerFactory
{
    /** The recognized transports; anything else falls back to the (warning) log transport. */
    public const TRANSPORTS = ['native', 'api', 'log'];

    public static function fromConfig(): Mailer
    {
        $transport = Config::mailTransport();

        return match ($transport) {
            'native' => new NativeMailer(Config::mailFrom()),
            'api'    => new ApiMailer(Config::mailApiEndpoint(), Config::mailApiKey(), Config::mailFrom()),
            'log'    => new LogMailer(Config::mailLogPath()),
            // Unknown value: fall back to the log transport, flagged so each send
            // logs a warning (a typo must never masquerade as delivery).
            default  => new LogMailer(Config::mailLogPath(), $transport),
        };
    }

    /**
     * The transport that {@see fromConfig()} will actually use, for operator
     * diagnostics (the `mail:test` CLI) — the configured value if recognized,
     * otherwise `'log'` (the fallback). Reads the same source as `fromConfig()`.
     */
    public static function resolvedTransport(): string
    {
        $transport = Config::mailTransport();
        return in_array($transport, self::TRANSPORTS, true) ? $transport : 'log';
    }
}
