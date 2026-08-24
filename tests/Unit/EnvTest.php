<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Support\Env;
use PHPUnit\Framework\TestCase;

/**
 * The .env parser (SUP-5/SUP-9): quote stripping, inline-comment handling for
 * unquoted values, `export` lines, and real-env-beats-.env precedence.
 */
final class EnvTest extends TestCase
{
    /** @var list<string> keys to unset after each test */
    private array $keys = [];

    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'nb-env-') ?: '';
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
        @unlink($this->file);
    }

    private function load(string $contents, string ...$keys): void
    {
        $this->keys = array_values([...$this->keys, ...$keys]);
        file_put_contents($this->file, $contents);
        Env::load($this->file);
    }

    public function test_an_unquoted_inline_comment_is_stripped(): void
    {
        // SUP-5: `KEY=secret # note` must not store the comment as part of the
        // (often secret) value.
        $this->load("NB_ENVTEST_KEY=re_abc123 # production key\n", 'NB_ENVTEST_KEY');
        self::assertSame('re_abc123', getenv('NB_ENVTEST_KEY'));
    }

    public function test_a_quoted_value_keeps_its_hash(): void
    {
        $this->load('NB_ENVTEST_Q="pa#ss word"' . "\n", 'NB_ENVTEST_Q');
        self::assertSame('pa#ss word', getenv('NB_ENVTEST_Q'), 'a # inside quotes is part of the value');
    }

    public function test_a_value_with_no_comment_is_untouched(): void
    {
        $this->load("NB_ENVTEST_PLAIN=re_abc123\n", 'NB_ENVTEST_PLAIN');
        self::assertSame('re_abc123', getenv('NB_ENVTEST_PLAIN'));
    }

    public function test_an_export_prefix_is_accepted(): void
    {
        $this->load("export NB_ENVTEST_EXP=value\n", 'NB_ENVTEST_EXP');
        self::assertSame('value', getenv('NB_ENVTEST_EXP'));
    }

    public function test_comment_and_blank_lines_are_skipped(): void
    {
        $this->load("# a comment\n\nNB_ENVTEST_AFTER=ok\n", 'NB_ENVTEST_AFTER');
        self::assertSame('ok', getenv('NB_ENVTEST_AFTER'));
    }

    public function test_the_real_environment_wins_over_the_dotenv_file(): void
    {
        putenv('NB_ENVTEST_PREC=from-real-env');
        $this->keys[] = 'NB_ENVTEST_PREC';
        $this->load("NB_ENVTEST_PREC=from-file\n", 'NB_ENVTEST_PREC');
        self::assertSame('from-real-env', getenv('NB_ENVTEST_PREC'), '.env never overrides a real env var');
    }
}
