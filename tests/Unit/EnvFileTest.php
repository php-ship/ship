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

    private function writeTempEnv(string $contents): string
    {
        $path = sys_get_temp_dir() . '/ship-env-file-test-' . bin2hex(random_bytes(8));
        file_put_contents($path, $contents);
        $this->filesToClean[] = $path;

        return $path;
    }
}
