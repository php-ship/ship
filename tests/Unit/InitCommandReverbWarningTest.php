<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ReverbService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Covers InitCommand::warnAboutMissingReverbPackageIfNeeded(). The registry here only contains
 * ReverbService, so the interactive picker asks exactly one service question (broadcasting), followed
 * by the PHP version prompt -- matching the two lines fed via setInputs().
 */
final class InitCommandReverbWarningTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-init-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    private function runInit(string $broadcastingAnswer): string
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry([new ReverbService()]));
        $tester = new CommandTester($command);
        $tester->setInputs([$broadcastingAnswer, '8.4']);
        $tester->execute([]);

        return $tester->getDisplay();
    }

    public function test_it_warns_when_reverb_is_selected_without_the_package_installed(): void
    {
        $output = $this->runInit('Laravel Reverb (WebSockets)');

        self::assertStringContainsString('laravel/reverb', $output);
        self::assertStringContainsString('composer require laravel/reverb', $output);
    }

    public function test_it_stays_quiet_when_the_package_is_already_installed(): void
    {
        mkdir($this->projectRoot . '/vendor/laravel/reverb', recursive: true);

        $output = $this->runInit('Laravel Reverb (WebSockets)');

        self::assertStringNotContainsString('composer require laravel/reverb', $output);
    }

    public function test_it_stays_quiet_when_reverb_was_not_selected(): void
    {
        $output = $this->runInit('None');

        self::assertStringNotContainsString('composer require laravel/reverb', $output);
    }
}
