<?php

declare(strict_types=1);

namespace Nimbus\Mail;

/**
 * The default transport: append the message to a local file instead of sending
 * it. A fresh install and CI work with zero mail configuration, and a developer
 * can read the reset link straight out of the log — while nothing leaves the box.
 *
 * The captured file holds reset links (secrets), so it lives under `storage/`
 * (never web-served) — the same place as the page cache.
 *
 * It is also the **fail-safe fallback** for an unrecognized `MAIL_TRANSPORT`
 * ({@see MailerFactory}). In that case `$unknownTransport` carries the bad value,
 * and every `send()` logs a loud warning — because a typo silently routing mail
 * to a file that reports *success* is exactly the false-delivery trap SUP-1
 * closes. The warning fires at send time (the moment a message is mis-routed),
 * not at construction (which happens on every request whether or not mail is
 * sent). An intentional `MAIL_TRANSPORT=log` leaves `$unknownTransport` null and
 * never warns.
 */
final class LogMailer implements Mailer
{
    public function __construct(private string $path, private ?string $unknownTransport = null)
    {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        if ($this->unknownTransport !== null) {
            error_log(sprintf(
                '[nimbus mail] MAIL_TRANSPORT "%s" is not one of native|api|log — mail is being '
                . 'written to the log, NOT delivered. Fix MAIL_TRANSPORT (see `nimbus mail:test`).',
                $this->unknownTransport,
            ));
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new MailerException("Cannot create mail log directory: {$dir}");
        }

        $block = sprintf(
            "==== %s ====\nTo: %s\nSubject: %s\n\n%s\n\n",
            date('c'),
            $to,
            $subject,
            $textBody,
        );

        if (@file_put_contents($this->path, $block, FILE_APPEND | LOCK_EX) === false) {
            throw new MailerException("Cannot write to mail log: {$this->path}");
        }
    }
}
