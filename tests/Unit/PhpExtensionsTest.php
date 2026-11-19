<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\PhpExtensions;
use Ship\Services\ServiceRegistry;
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
}
