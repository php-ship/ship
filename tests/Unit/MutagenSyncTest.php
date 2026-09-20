<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Sync\MutagenSync;

final class MutagenSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SHIP_MUTAGEN');
    }

    /**
     * @return iterable<string, array{0: string|false, 1: bool}>
     */
    public static function envValues(): iterable
    {
        yield 'unset' => [false, false];
        yield 'empty string' => ['', false];
        yield '"1"' => ['1', true];
        yield '"true"' => ['true', true];
        yield '"TRUE" (case-insensitive)' => ['TRUE', true];
        yield '"  1  " (whitespace-tolerant)' => ['  1  ', true];
        yield '"0"' => ['0', false];
        yield '"yes" (only 1/true accepted)' => ['yes', false];
    }

    /**
     * @param string|false $value
     */
    #[DataProvider('envValues')]
    public function test_is_enabled_reads_the_ship_mutagen_environment_variable(string|false $value, bool $expected): void
    {
        if ($value === false) {
            putenv('SHIP_MUTAGEN');
        } else {
            putenv("SHIP_MUTAGEN={$value}");
        }

        self::assertSame($expected, MutagenSync::isEnabled());
    }
}
