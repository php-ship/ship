<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Frameworks\LaravelAdapter;

final class LaravelAdapterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ship-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    public function test_it_detects_a_laravel_project(): void
    {
        touch($this->tmpDir . '/artisan');
        touch($this->tmpDir . '/composer.json');

        self::assertTrue((new LaravelAdapter())->detect($this->tmpDir));
    }

    public function test_it_does_not_detect_a_non_laravel_project(): void
    {
        touch($this->tmpDir . '/composer.json');

        self::assertFalse((new LaravelAdapter())->detect($this->tmpDir));
    }

    public function test_it_exposes_the_artisan_console_command(): void
    {
        self::assertSame(['artisan' => 'php artisan'], (new LaravelAdapter())->consoleCommands());
    }

    public function test_it_returns_artisan_optimize_as_the_release_command(): void
    {
        self::assertSame(['php artisan optimize'], (new LaravelAdapter())->releaseCommands());
    }
}
