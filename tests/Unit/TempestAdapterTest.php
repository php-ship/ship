<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\ConfigTestCommand;
use Ship\Console\Commands\InitCommand;
use Ship\Docker\EntrypointScriptBuilder;
use Ship\Frameworks\Frameworks;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Frameworks\TempestAdapter;
use Ship\Node\PackageManagerVersions;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class TempestAdapterTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-tempest-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * A project as `composer create-project tempest/app` makes it.
     */
    private function tempestProject(?string $phpConstraint = '^8.5'): void
    {
        file_put_contents($this->projectRoot . '/tempest', "#!/usr/bin/env php\n<?php\n");
        file_put_contents($this->projectRoot . '/composer.json', json_encode(['require' => ['tempest/framework' => '^3.0']]));

        if ($phpConstraint !== null) {
            file_put_contents($this->projectRoot . '/composer.lock', json_encode(['packages' => [
                ['name' => 'tempest/framework', 'version' => 'v3.21.0', 'require' => ['php' => $phpConstraint]],
            ]]));
        }
    }

    public function test_it_detects_a_tempest_project(): void
    {
        $this->tempestProject();

        self::assertTrue((new TempestAdapter())->detect($this->projectRoot));
        self::assertSame([TempestAdapter::class], array_map(get_class(...), Frameworks::detected($this->projectRoot)));
    }

    /**
     * A file named "tempest" alone could be anything.
     */
    public function test_a_tempest_file_without_the_framework_is_not_tempest(): void
    {
        file_put_contents($this->projectRoot . '/tempest', "anything\n");
        file_put_contents($this->projectRoot . '/composer.json', '{}');

        self::assertFalse((new TempestAdapter())->detect($this->projectRoot));
    }

    public function test_it_offers_ship_tempest_and_describes_the_release(): void
    {
        $adapter = new TempestAdapter();

        self::assertSame(['tempest' => 'php tempest'], $adapter->consoleCommands());
        self::assertSame('tempest', $adapter->frameworkName());
        self::assertSame('BASE_URI', $adapter->siteUrlSetting());
        self::assertNull($adapter->healthPath($this->projectRoot));
        self::assertSame(['ENVIRONMENT', 'SIGNING_KEY'], $adapter->requiredEnv($this->projectRoot));
    }

    /**
     * Caches baked in from local development are cleared before discovery is generated, and
     * .tempest/ is handed to www-data afterwards.
     */
    public function test_boot_clears_every_cache_then_generates_discovery(): void
    {
        $commands = (new TempestAdapter())->releaseCommands();

        self::assertSame(['php tempest cache:clear --internal --all --force', 'php tempest discovery:generate'], $commands);

        $script = (new EntrypointScriptBuilder())->build($commands);
        self::assertLessThan(strpos($script, 'chown -R www-data'), strpos($script, 'php tempest discovery:generate'));
        self::assertStringContainsString('.tempest; do', $script);
    }

    public function test_the_minimum_php_comes_from_the_installed_framework(): void
    {
        $this->tempestProject('^8.6');
        self::assertSame('8.6', (new TempestAdapter())->minimumPhpVersion($this->projectRoot));

        unlink($this->projectRoot . '/composer.lock');
        self::assertSame('8.5', (new TempestAdapter())->minimumPhpVersion($this->projectRoot));
    }

    public function test_every_built_in_framework_is_in_the_one_list(): void
    {
        self::assertSame(
            [LaravelAdapter::class, SymfonyAdapter::class, TempestAdapter::class],
            array_map(get_class(...), Frameworks::builtIn()),
        );
    }

    /**
     * `ship init` starts at the framework's minimum and refuses an older PHP.
     */
    public function test_init_defaults_to_the_frameworks_php_and_refuses_an_older_one(): void
    {
        $this->tempestProject();

        $tester = new CommandTester(new InitCommand($this->projectRoot, new ServiceRegistry([]), new PackageManagerVersions(static fn (string $url): ?string => null)));
        $tester->setInputs(['', 'no', '', '8.4', '', '']);
        $tester->execute([]);

        self::assertStringContainsString('Tempest needs PHP 8.5 or newer', $tester->getDisplay());
        self::assertSame('8.5', ShipConfig::fromFile($this->projectRoot . '/ship.json')->phpVersion);
    }

    public function test_config_test_reports_a_php_too_old_for_the_framework(): void
    {
        $this->tempestProject();
        (new ShipConfig(phpVersion: '8.4', services: []))->toFile($this->projectRoot . '/ship.json');

        $tester = new CommandTester(new ConfigTestCommand($this->projectRoot, new ServiceRegistry([])));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Tempest needs PHP 8.5 or newer', $tester->getDisplay());
    }
}
