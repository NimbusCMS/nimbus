<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Mail\ApiMailer;
use Nimbus\Mail\LogMailer;
use Nimbus\Mail\MailerException;
use Nimbus\Mail\NativeMailer;
use Nimbus\Tests\Support\MailSpy;
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

    // -------------------------------------------------- SUP-1: loud fallback

    public function test_log_mailer_warns_on_send_when_it_is_an_unknown_transport_fallback(): void
    {
        $dir = sys_get_temp_dir() . '/nimbus-mail-' . bin2hex(random_bytes(4));
        $log = $dir . '/errors.log';
        @mkdir($dir, 0o775, true);
        $prev = ini_set('error_log', $log);

        (new LogMailer($dir . '/mail.log', 'nativ'))->send('to@site.test', 'Hi', 'body');

        ini_set('error_log', (string) $prev);
        $errors = (string) @file_get_contents($log);
        self::assertStringContainsString('MAIL_TRANSPORT', $errors, 'a mis-routed send is loud');
        self::assertStringContainsString('nativ', $errors, 'the bad value is named');

        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function test_log_mailer_is_silent_as_the_intentional_transport(): void
    {
        $dir = sys_get_temp_dir() . '/nimbus-mail-' . bin2hex(random_bytes(4));
        $log = $dir . '/errors.log';
        @mkdir($dir, 0o775, true);
        $prev = ini_set('error_log', $log);

        (new LogMailer($dir . '/mail.log'))->send('to@site.test', 'Hi', 'body'); // no flag

        ini_set('error_log', (string) $prev);
        self::assertStringNotContainsString('MAIL_TRANSPORT', (string) @file_get_contents($log));

        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    // ----------------------------------------- SUP-6: RFC 2047 subject encoding

    public function test_native_mailer_encodes_a_non_ascii_subject(): void
    {
        MailSpy::reset();
        (new NativeMailer('from@site.test'))->send('to@site.test', 'Café Süd', 'body');

        $sent = MailSpy::$last;
        self::assertNotNull($sent);
        self::assertStringStartsWith('=?UTF-8?B?', $sent['subject'], 'the subject is RFC 2047 encoded');
        self::assertDoesNotMatchRegularExpression('/[\x80-\xFF]/', $sent['subject'], 'no raw non-ASCII bytes in the header');
        self::assertSame('Café Süd', mb_decode_mimeheader($sent['subject']), 'and it round-trips');
    }

    public function test_native_mailer_leaves_an_ascii_subject_verbatim(): void
    {
        MailSpy::reset();
        (new NativeMailer('from@site.test'))->send('to@site.test', 'Reset your password', 'body');

        self::assertSame('Reset your password', MailSpy::$last['subject'] ?? null);
    }

    public function test_native_mailer_still_rejects_a_crlf_subject_before_encoding(): void
    {
        // The encode step must run AFTER the CR/LF guard — a raw-newline subject
        // is still rejected, never silently base64-wrapped.
        $this->expectException(MailerException::class);
        (new NativeMailer('from@site.test'))->send('to@site.test', "Hi\r\nBcc: e@evil.test", 'body');
    }
}
