<?php

declare(strict_types=1);

namespace Nimbus\Mail;

/**
 * PHP `mail()` (sendmail/MTA) transport — zero-dependency delivery for a host
 * that already has a mail transfer agent (common on shared hosting).
 *
 * Header injection is the classic `mail()` foot-gun: a CR/LF in the recipient,
 * subject or From smuggles extra headers (a Bcc to the attacker). Core controls
 * every header here, but we still **reject** any CR/LF in the address/subject
 * and validate the recipient, so a bad stored value can never inject a header.
 */
final class NativeMailer implements Mailer
{
    public function __construct(private string $from)
    {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $to      = $this->assertHeaderSafe($to, 'recipient');
        $subject = $this->assertHeaderSafe($subject, 'subject');
        $from    = $this->assertHeaderSafe($this->from, 'from');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailerException('Invalid recipient address.');
        }

        $headers = 'From: ' . $from . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        if (!@mail($to, $subject, $textBody, $headers)) {
            throw new MailerException('mail() failed to hand off the message.');
        }
    }

    /** Reject a value carrying a CR or LF — the only way to inject a header. */
    private function assertHeaderSafe(string $value, string $what): string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new MailerException("Illegal newline in mail {$what}.");
        }
        return $value;
    }
}
