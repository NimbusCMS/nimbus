<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Mail\ApiMailer;
use Nimbus\Mail\LogMailer;
use Nimbus\Mail\MailerFactory;
use Nimbus\Mail\NativeMailer;
use PHPUnit\Framework\TestCase;

/**
 * Transport selection: recognized transports build their mailer; an unrecognized
 * `MAIL_TRANSPORT` falls back to the log transport (loudly — see MailerTest) so a
 * typo can never masquerade as delivery (SUP-1).
 */
final class MailerFactoryTest extends TestCase
{
    private string|false $prev;

    protected function setUp(): void
    {
        $this->prev = getenv('MAIL_TRANSPORT');
        // The api transport needs an endpoint + key to construct.
        putenv('MAIL_API_ENDPOINT=https://api.example.test/emails');
        putenv('MAIL_API_KEY=k');
        putenv('MAIL_FROM=from@site.test');
    }

    protected function tearDown(): void
    {
        $this->prev === false ? putenv('MAIL_TRANSPORT') : putenv('MAIL_TRANSPORT=' . $this->prev);
        putenv('MAIL_API_ENDPOINT');
        putenv('MAIL_API_KEY');
        putenv('MAIL_FROM');
    }

    public function test_native_builds_the_native_mailer(): void
    {
        putenv('MAIL_TRANSPORT=native');
        self::assertInstanceOf(NativeMailer::class, MailerFactory::fromConfig());
    }

    public function test_api_builds_the_api_mailer(): void
    {
        putenv('MAIL_TRANSPORT=api');
        self::assertInstanceOf(ApiMailer::class, MailerFactory::fromConfig());
    }

    public function test_log_builds_the_log_mailer(): void
    {
        putenv('MAIL_TRANSPORT=log');
        self::assertInstanceOf(LogMailer::class, MailerFactory::fromConfig());
    }

    public function test_an_unknown_transport_falls_back_to_the_log_mailer(): void
    {
        putenv('MAIL_TRANSPORT=nativ'); // a one-letter typo
        self::assertInstanceOf(LogMailer::class, MailerFactory::fromConfig());
    }

    public function test_resolved_transport_reports_the_effective_transport(): void
    {
        putenv('MAIL_TRANSPORT=native');
        self::assertSame('native', MailerFactory::resolvedTransport());

        putenv('MAIL_TRANSPORT=nativ');
        self::assertSame('log', MailerFactory::resolvedTransport(), 'an unknown value resolves to the log fallback');
    }
}
