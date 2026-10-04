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
     * A UTF-8 BOM (several Windows editors, and PowerShell's own Out-File/Set-Content, write one
     * by default) has to be stripped from the first line's key, or that variable is never found
     * by any exact lookup even though the file reads as correct in an editor that hides the BOM
     * on display.
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
     * `export KEY=value` -- valid shell syntax Laravel/Compose both already accept, and a real
     * pattern for a .env.production meant to also be `source`-able directly -- must strip the
     * `export ` prefix, or every lookup against the real "KEY" would see it as never set at all.
     */
    public function test_it_strips_a_leading_export_keyword(): void
    {
        $path = $this->writeTempEnv("export MEILISEARCH_KEY=abc\nexport   DB_HOST=mysql\n");

        self::assertSame(['MEILISEARCH_KEY' => 'abc', 'DB_HOST' => 'mysql'], EnvFile::parse($path));
    }

    /**
     * A trailing " # comment" must be stripped, not kept as part of the value verbatim -- the
     * "comment" isn't a comment to anything reading this value afterward.
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

    /**
     * A *quoted* value followed by a comment -- `KEY="three" # note` -- has to find the closing
     * quote explicitly and only comment-strip what comes after it; checking only whether the
     * closing quote is the value's very last character would miss this (a trailing comment makes
     * that false), reading the literal `"three" # note` instead -- quotes and comment included.
     * The real-world effect: DB_USERNAME="root" # comment would escape MySqlUsernameGuard
     * entirely, and a value handed to `docker compose build` for `${VAR}` interpolation would be
     * wrong (though not the containers Compose itself starts, which read the file directly rather
     * than through this reader).
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
     * An *escaped* quote inside a double-quoted value -- E="a\"b" -- must not end the value
     * early: scanning has to continue past it to the real closing quote, treating a backslash as
     * consuming whatever follows it, the same way a shell or a real dotenv parser already does.
     * The only consumers that ever read this (MySqlUsernameGuard, the `${VAR}` interpolation
     * handed to `docker compose build`) would otherwise see the truncated value -- Compose itself
     * reads the real file at container runtime, so the containers themselves are never affected
     * either way.
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
     * Single-quoted values have no escape mechanism at all in shell/dotenv convention -- unlike
     * double-quoted ones, the first matching quote is always the real closing one.
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
