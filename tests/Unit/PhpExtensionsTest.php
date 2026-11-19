<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\InitCommand;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\PhpExtensions;
use Ship\Node\PackageManagerVersions;
use Ship\Services\MongoDbService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The PHP extensions a project's packages declare are installed in its image, so `composer
 * install` in the build doesn't refuse for lack of one (bcmath, required by dragon-code/support).
 */
final class PhpExtensionsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-php-extensions-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
        file_put_contents($this->projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'dragon-code/support', 'require' => ['php' => '^8.1', 'ext-bcmath' => '*', 'ext-mbstring' => '*']],
                ['name' => 'laravel/framework', 'require' => ['ext-ctype' => '*', 'ext-Zend-OPcache' => '*']],
            ],
            'packages-dev' => [
                ['name' => 'some/dev-tool', 'require' => ['ext-imagick' => '*', 'ext-xdebug' => '*']],
            ],
        ]));
        file_put_contents($this->projectRoot . '/composer.json', json_encode(['require' => ['ext-gmp' => '*', 'laravel/framework' => '^12.0']]));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * Only what the images lack: mbstring, ctype and opcache are already there.
     */
    public function test_it_finds_what_the_packages_need_and_the_image_lacks(): void
    {
        // Xdebug isn't installed again: the development stage builds it itself.
        self::assertSame(['bcmath', 'gmp', 'imagick'], PhpExtensions::missingFromComposer($this->projectRoot));
        self::assertSame(['bcmath', 'gmp'], PhpExtensions::missingFromComposer($this->projectRoot, withDev: false));
    }

    public function test_nothing_without_composer_files(): void
    {
        self::assertSame([], PhpExtensions::missingFromComposer($this->projectRoot . '/nowhere'));
    }

    /**
     * The images install them, alongside ship.json's own list.
     */
    public function test_the_image_installs_them_with_ship_jsons_own(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: [], phpExtensions: ['gd']);
        $builder = new ComposeFileBuilder(new ServiceRegistry([]));

        $development = Yaml::parse($builder->build($config, ShipEnvironment::Development, projectRoot: $this->projectRoot));
        $production = Yaml::parse($builder->build($config, ShipEnvironment::Production, projectRoot: $this->projectRoot));

        self::assertSame('bcmath gmp imagick gd', $development['services']['app']['build']['args']['PHP_EXTENSIONS']);
        // Production leaves out what only development packages declare.
        self::assertSame('bcmath gmp gd', $production['services']['app']['build']['args']['PHP_EXTENSIONS']);
    }

    private function init(array $inputs, ?ServiceRegistry $registry = null): CommandTester
    {
        $tester = new CommandTester(new InitCommand($this->projectRoot, $registry ?? new ServiceRegistry([]), new PackageManagerVersions(static fn (string $url): ?string => null)));
        $tester->setInputs($inputs);
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function listed(): array
    {
        return json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true)['phpExtensions'] ?? [];
    }

    /**
     * What's added anyway is shown with the reason, and listing one of those, or one every image
     * has, is refused.
     */
    public function test_init_says_what_is_added_and_refuses_listing_it_again(): void
    {
        // Name; MongoDB as the database; no instances, no processes; the extensions (refused
        // twice); no networks; PHP; Node.
        $tester = $this->init(
            ['', 'Database', 'MongoDB', 'no', 'no', 'bcmath', 'intl', 'GD, apcu', '', '8.4', ''],
            new ServiceRegistry([new MongoDbService()]),
        );
        $display = (string) preg_replace('/\s*\n\s*!?\s*/', ' ', $tester->getDisplay());

        self::assertStringContainsString('bcmath (dragon-code/support)', $display);
        self::assertStringContainsString('mongodb (the MongoDB service)', $display);
        self::assertStringContainsString('bcmath is added already (dragon-code/support)', $display);
        self::assertStringContainsString('intl is in every image already', $display);
        self::assertSame(['gd', 'apcu'], $this->listed());
    }

    /**
     * A re-run starts from ship.json's list, without what the image now has anyway.
     */
    public function test_a_re_run_offers_the_list_without_what_is_added_anyway(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => [], 'phpExtensions' => ['gd', 'zip', 'bcmath']]));

        // Name; no processes; Enter keeps the offered list; no networks; PHP; Node.
        $tester = $this->init(['', 'no', '', '', '8.4', '']);
        $display = (string) preg_replace('/\s*\n\s*!?\s*/', ' ', $tester->getDisplay());

        self::assertStringContainsString('Other PHP extensions (optional) [gd]', $display);
        self::assertStringContainsString('the image has them anyway: zip, bcmath', $display);
        self::assertSame(['gd'], $this->listed());
    }
}
