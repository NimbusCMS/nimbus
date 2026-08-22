<?php

declare(strict_types=1);

namespace Nimbus\Mail;

/**
 * A transactional-provider transport: one HTTPS `POST {from,to,subject,text}`
 * with a bearer API key (Resend-compatible; point `MAIL_API_ENDPOINT` at any
 * provider of that shape). No SMTP, no MIME, no dependency — just an HTTPS call,
 * which makes "email works" a single key pasted at install time.
 *
 * Security posture: the endpoint MUST be `https` and its TLS certificate is
 * **verified** (never disabled); the API key is a secret read from config and is
 * **never** placed in an exception, a log, or a response; the recipient is
 * validated. A provider/transport failure raises {@see MailerException} — the
 * caller (the no-enumeration reset flow) swallows it and returns the generic
 * response, so a delivery error never changes what a client observes.
 */
final class ApiMailer implements Mailer
{
    public function __construct(
        private string $endpoint,
        private string $apiKey,
        private string $from,
        private int $timeoutSeconds = 10,
    ) {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        if (!str_starts_with(strtolower($this->endpoint), 'https://')) {
            throw new MailerException('Mail API endpoint must be https.');
        }
        if ($this->apiKey === '') {
            throw new MailerException('Mail API key is not configured.');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailerException('Invalid recipient address.');
        }

        $payload = json_encode([
            'from'    => $this->from,
            'to'      => [$to],
            'subject' => $subject,
            'text'    => $textBody,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            throw new MailerException('Could not initialise the mail request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            // TLS verification stays on — a reset link over a MITM'd channel is game over.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        // Never surface the key: report only the transport error / HTTP status.
        if ($response === false) {
            throw new MailerException('Mail provider request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new MailerException("Mail provider returned HTTP {$status}.");
        }
    }
}
