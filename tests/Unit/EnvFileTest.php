<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\EnvFile;

final class EnvFileTest extends TestCase
{
    /** @var list<string> */
    private array $filesToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToClean as $file) {
            @unlink($file);
        }
    }

    public function test_it_parses_plain_key_value_lines(): void
    {
        $path = $this->writeTempEnv("DB_PASSWORD=secret\nAPP_NAME=Acme\n");

        self::assertSame(['DB_PASSWORD' => 'secret', 'APP_NAME' => 'Acme'], EnvFile::parse($path));
    }

    public function test_it_skips_comments_and_blank_lines(): void
    {
        $path = $this->writeTempEnv("# a comment\n\nAPP_NAME=Acme\n");

        self::assertSame(['APP_NAME' => 'Acme'], EnvFile::parse($path));
    }

    public function test_it_strips_matching_surrounding_quotes(): void
    {
        $path = $this->writeTempEnv("APP_NAME=\"Acme Inc\"\nAPP_ENV='production'\n");

        self::assertSame(['APP_NAME' => 'Acme Inc', 'APP_ENV' => 'production'], EnvFile::parse($path));
    }

    public function test_a_missing_file_returns_nothing_instead_of_failing(): void
    {
        self::assertSame([], EnvFile::parse(sys_get_temp_dir() . '/ship-env-file-does-not-exist'));
    }

    /**
     * Regression coverage for a real bug found via a fourth independent audit, confirmed live:
     * `export KEY=value` -- valid shell syntax Laravel/Compose both already accept, and a real
     * pattern for a .env.production meant to also be `source`-able directly -- kept "export KEY"
     * as the variable name verbatim, so every lookup against the real "KEY" silently saw it as
     * never set at all.
     */
    public function test_it_strips_a_leading_export_keyword(): void
    {
        $path = $this->writeTempEnv("export MEILISEARCH_KEY=abc\nexport   DB_HOST=mysql\n");

        self::assertSame(['MEILISEARCH_KEY' => 'abc', 'DB_HOST' => 'mysql'], EnvFile::parse($path));
    }

    /**
     * Regression coverage for a real bug found the same way: a trailing " # comment" was kept as
     * part of the value verbatim instead of being stripped, corrupting it outright.
     */
    public function test_it_strips_a_trailing_inline_comment_on_an_unquoted_value(): void
    {
        $path = $this->writeTempEnv("DB_PASSWORD=secret # a trailing comment\n");

        self::assertSame(['DB_PASSWORD' => 'secret'], EnvFile::parse($path));
    }

    /**
     * A literal "#" inside a quoted value is not a comment -- the closing quote is the actual end
     * of the value, not wherever "#" happens to appear.
     */
    public function test_a_hash_inside_quotes_is_kept_as_part_of_the_value(): void
    {
        $path = $this->writeTempEnv("APP_NAME=\"Acme # Inc\"\n");

        self::assertSame(['APP_NAME' => 'Acme # Inc'], EnvFile::parse($path));
    }

    private function writeTempEnv(string $contents): string
    {
        $path = sys_get_temp_dir() . '/ship-env-file-test-' . bin2hex(random_bytes(8));
        file_put_contents($path, $contents);
        $this->filesToClean[] = $path;

        return $path;
    }
}
