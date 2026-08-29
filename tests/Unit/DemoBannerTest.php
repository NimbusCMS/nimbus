<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Site\DemoBanner;
use PHPUnit\Framework\TestCase;

/**
 * The core demo banner (rendered when NIMBUS_DEMO is on) — so any theme works in
 * the hosted sandbox without carrying demo markup, and no theme hardcodes the
 * demo login.
 */
final class DemoBannerTest extends TestCase
{
    private const HTML = '<!doctype html><html><head></head><body class="x"><main>hi</main></body></html>';

    protected function tearDown(): void
    {
        foreach (['NIMBUS_DEMO', 'NIMBUS_DEMO_EMAIL', 'NIMBUS_DEMO_PASSWORD'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    private function demo(string $email = '', string $pass = ''): void
    {
        putenv('NIMBUS_DEMO=true');
        $_ENV['NIMBUS_DEMO'] = 'true';
        if ($email !== '') {
            putenv("NIMBUS_DEMO_EMAIL={$email}");
            $_ENV['NIMBUS_DEMO_EMAIL'] = $email;
            putenv("NIMBUS_DEMO_PASSWORD={$pass}");
            $_ENV['NIMBUS_DEMO_PASSWORD'] = $pass;
        }
    }

    public function test_off_by_default_the_page_is_untouched(): void
    {
        self::assertSame(self::HTML, DemoBanner::inject(self::HTML), 'no banner on a normal install');
    }

    public function test_in_demo_mode_the_banner_is_inserted_after_the_body(): void
    {
        $this->demo();
        $out = DemoBanner::inject(self::HTML);

        self::assertStringContainsString('nb-demo-banner', $out);
        self::assertStringContainsString('Live demo', $out);
        // Right after the opening body tag, before the theme's own content.
        self::assertMatchesRegularExpression('/<body class="x"><style[^>]*>.*nb-demo-banner.*<\/style><div class="nb-demo-banner">/s', $out);
        self::assertStringContainsString('<main>hi</main>', $out, 'the page content survives');
    }

    public function test_credentials_come_from_env_and_are_escaped(): void
    {
        $this->demo('demo@nimbuscms.dev', 'explore" <b>');
        $out = DemoBanner::inject(self::HTML);

        self::assertStringContainsString('demo@nimbuscms.dev', $out);
        self::assertStringContainsString('/admin', $out);
        self::assertStringNotContainsString('<b>', $out, 'a hostile password is escaped, not rendered as markup');
    }

    public function test_no_credentials_line_when_email_is_unset(): void
    {
        $this->demo(); // demo on, but no email/password configured
        $out = DemoBanner::inject(self::HTML);

        self::assertStringContainsString('nb-demo-banner', $out);
        self::assertStringNotContainsString('/admin', $out, 'no login line without configured credentials');
    }
}
