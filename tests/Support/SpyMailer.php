<?php

declare(strict_types=1);

namespace Nimbus\Tests\Support;

use Nimbus\Mail\Mailer;
use Nimbus\Mail\MailerException;

/** A test mailer that records what would be sent (and can simulate a failure). */
final class SpyMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(string $to, string $subject, string $textBody): void
    {
        if ($this->fail) {
            throw new MailerException('simulated delivery failure');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $textBody];
    }

    /** The reset token from the most recent message, or null. */
    public function lastToken(): ?string
    {
        $body = $this->sent === [] ? '' : $this->sent[array_key_last($this->sent)]['body'];
        return preg_match('~/admin/reset\?token=([a-f0-9]+)~', $body, $m) === 1 ? $m[1] : null;
    }
}
