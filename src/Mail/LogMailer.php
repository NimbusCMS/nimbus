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
 */
final class LogMailer implements Mailer
{
    public function __construct(private string $path)
    {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
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
