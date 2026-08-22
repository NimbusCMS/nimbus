<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Mail\ApiMailer;
use Nimbus\Mail\LogMailer;
use Nimbus\Mail\MailerException;
use Nimbus\Mail\NativeMailer;
use PHPUnit\Framework\TestCase;

/**
 * The mail transports' safety rails — the parts that must hold regardless of a
 * real MTA/provider: header-injection rejection, https-only + key-required for
 * the API transport, and that LogMailer captures the message.
 */
final class MailerTest extends TestCase
{
    public function test_native_mailer_rejects_a_newline_in_the_recipient(): void
    {
        $this->expectException(MailerException::class);
        (new NativeMailer('from@site.test'))->send("victim@site.test\r\nBcc: attacker@evil.test", 'Hi', 'body');
    }

    public function test_native_mailer_rejects_a_newline_in_the_subject(): void
    {
        $this->expectException(MailerException::class);
        (new NativeMailer('from@site.test'))->send('victim@site.test', "Hi\r\nBcc: attacker@evil.test", 'body');
    }

    public function test_native_mailer_rejects_an_invalid_recipient(): void
    {
        $this->expectException(MailerException::class);
        (new NativeMailer('from@site.test'))->send('not-an-email', 'Hi', 'body');
    }

    public function test_api_mailer_refuses_a_non_https_endpoint(): void
    {
        $this->expectException(MailerException::class);
        (new ApiMailer('http://api.example.test/emails', 'key', 'from@site.test'))
            ->send('to@site.test', 'Hi', 'body');
    }

    public function test_api_mailer_requires_a_key(): void
    {
        $this->expectException(MailerException::class);
        (new ApiMailer('https://api.example.test/emails', '', 'from@site.test'))
            ->send('to@site.test', 'Hi', 'body');
    }

    public function test_log_mailer_captures_the_message(): void
    {
        $path = sys_get_temp_dir() . '/nimbus-mail-' . bin2hex(random_bytes(4)) . '/mail.log';
        (new LogMailer($path))->send('to@site.test', 'Subject line', 'Body with https://x/reset?token=abc');

        $written = (string) file_get_contents($path);
        self::assertStringContainsString('To: to@site.test', $written);
        self::assertStringContainsString('Subject line', $written);
        self::assertStringContainsString('token=abc', $written);

        @unlink($path);
        @rmdir(dirname($path));
    }
}
