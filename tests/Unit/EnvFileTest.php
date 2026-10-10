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

    /**
     * A UTF-8 BOM would otherwise become part of the first key.
     */
    public function test_it_strips_a_leading_utf8_bom(): void
    {
        $path = $this->writeTempEnv("\xEF\xBB\xBFDB_PASSWORD=secret\nAPP_NAME=Acme\n");

        self::assertSame(['DB_PASSWORD' => 'secret', 'APP_NAME' => 'Acme'], EnvFile::parse($path));
    }

    public function test_a_missing_file_returns_nothing_instead_of_failing(): void
    {
        self::assertSame([], EnvFile::parse(sys_get_temp_dir() . '/ship-env-file-does-not-exist'));
    }

    /**
     * `export KEY=value` is valid in a .env file meant to be `source`-able.
     */
    public function test_it_strips_a_leading_export_keyword(): void
    {
        $path = $this->writeTempEnv("export MEILISEARCH_KEY=abc\nexport   DB_HOST=mysql\n");

        self::assertSame(['MEILISEARCH_KEY' => 'abc', 'DB_HOST' => 'mysql'], EnvFile::parse($path));
    }

    /**
     * A trailing " # comment" isn't part of an unquoted value.
     */
    public function test_it_strips_a_trailing_inline_comment_on_an_unquoted_value(): void
    {
        $path = $this->writeTempEnv("DB_PASSWORD=secret # a trailing comment\n");

        self::assertSame(['DB_PASSWORD' => 'secret'], EnvFile::parse($path));
    }

    /**
     * A "#" inside a quoted value is not a comment.
     */
    public function test_a_hash_inside_quotes_is_kept_as_part_of_the_value(): void
    {
        $path = $this->writeTempEnv("APP_NAME=\"Acme # Inc\"\n");

        self::assertSame(['APP_NAME' => 'Acme # Inc'], EnvFile::parse($path));
    }

    /**
     * For `KEY="three" # note` the closing quote ends the value and the comment is dropped.
     * Otherwise `DB_USERNAME="root" # comment` would get past MySqlUsernameGuard.
     */
    public function test_it_strips_a_trailing_comment_after_a_quoted_values_closing_quote(): void
    {
        $path = $this->writeTempEnv("C=\"three\" # note\nDB_USERNAME=\"root\" # a comment\n");

        self::assertSame(['C' => 'three', 'DB_USERNAME' => 'root'], EnvFile::parse($path));
    }

    public function test_an_unterminated_quote_falls_back_to_the_raw_value(): void
    {
        $path = $this->writeTempEnv('UNTERMINATED="oops' . "\n");

        self::assertSame(['UNTERMINATED' => '"oops'], EnvFile::parse($path));
    }

    /**
     * An escaped quote inside a double-quoted value (`E="a\"b"`) doesn't end the value.
     */
    public function test_an_escaped_quote_inside_a_double_quoted_value_does_not_end_it_early(): void
    {
        $path = $this->writeTempEnv('E="a\"b"' . "\n");

        self::assertSame(['E' => 'a"b'], EnvFile::parse($path));
    }

    public function test_an_escaped_backslash_inside_a_double_quoted_value_is_unescaped(): void
    {
        $path = $this->writeTempEnv('BACKSLASH="path\\\\end"' . "\n");

        self::assertSame(['BACKSLASH' => 'path\\end'], EnvFile::parse($path));
    }

    /**
     * Single-quoted values have no escape mechanism.
     */
    public function test_a_backslash_inside_a_single_quoted_value_is_kept_literal(): void
    {
        $path = $this->writeTempEnv("SINGLE='a\\b'\n");

        self::assertSame(['SINGLE' => 'a\\b'], EnvFile::parse($path));
    }

    private function writeTempEnv(string $contents): string
    {
        $path = sys_get_temp_dir() . '/ship-env-file-test-' . bin2hex(random_bytes(8));
        file_put_contents($path, $contents);
        $this->filesToClean[] = $path;

        return $path;
    }
}
