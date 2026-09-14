<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Frameworks\SymfonyAdapter;

final class SymfonyAdapterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ship-test-' . uniqid();
        mkdir($this->tmpDir . '/bin', recursive: true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/bin/*') ?: []);
        rmdir($this->tmpDir . '/bin');
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    public function test_it_detects_a_symfony_project(): void
    {
        touch($this->tmpDir . '/bin/console');
        touch($this->tmpDir . '/composer.json');

        self::assertTrue((new SymfonyAdapter())->detect($this->tmpDir));
    }

    public function test_it_does_not_detect_a_non_symfony_project(): void
    {
        touch($this->tmpDir . '/composer.json');

        self::assertFalse((new SymfonyAdapter())->detect($this->tmpDir));
    }

    public function test_it_exposes_the_console_command(): void
    {
        self::assertSame(['console' => 'php bin/console'], (new SymfonyAdapter())->consoleCommands());
    }

    public function test_it_returns_cache_clear_as_the_only_release_command(): void
    {
        self::assertSame(['php bin/console cache:clear'], (new SymfonyAdapter())->releaseCommands());
    }
}
