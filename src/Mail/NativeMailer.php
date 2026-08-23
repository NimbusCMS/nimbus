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

        // RFC 5322 headers are ASCII: a non-ASCII subject (a Unicode site title)
        // must be RFC 2047 encoded or clients render mojibake (SUP-6). This runs
        // AFTER the CR/LF guard (which must see the raw value); the encoded word
        // is pure ASCII. mb_encode_mimeheader chunks/folds to the 75-char
        // encoded-word limit and leaves a pure-ASCII subject untouched.
        $subject = $this->encodeSubject($subject);

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

    /**
     * RFC 2047 encode a non-ASCII subject (leaving pure ASCII byte-identical). The
     * base64 alphabet is CR/LF-free, so the encoded form can never carry a header
     * injection; mb_encode_mimeheader handles the 75-char encoded-word chunking.
     */
    private function encodeSubject(string $subject): string
    {
        if (mb_check_encoding($subject, 'ASCII')) {
            return $subject;
        }
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }
}
