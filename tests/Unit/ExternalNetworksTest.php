<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\InitCommand;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Node\PackageManagerVersions;
use Ship\Services\ReverbService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * ship.json's "externalNetwork": one Docker network, or a list of them.
 */
final class ExternalNetworksTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-networks-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    private function config(mixed $externalNetwork): ShipConfig
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => ['broadcasting' => 'reverb'], 'externalNetwork' => $externalNetwork]));

        return ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    private function written(): mixed
    {
        return json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true)['externalNetwork'] ?? null;
    }

    /**
     * One network stays a string, so existing files (and other implementations) read the same.
     */
    public function test_one_network_is_a_string_and_several_are_a_list(): void
    {
        $one = $this->config('proxy');
        self::assertSame(['proxy'], $one->externalNetworks);
        self::assertSame('proxy', $one->externalNetwork);
        $one->toFile($this->projectRoot . '/ship.json');
        self::assertSame('proxy', $this->written());

        $several = $this->config(['proxy', 'monitoring']);
        self::assertSame(['proxy', 'monitoring'], $several->externalNetworks);
        self::assertSame('proxy', $several->externalNetwork);
        $several->toFile($this->projectRoot . '/ship.json');
        self::assertSame(['proxy', 'monitoring'], $this->written());

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/ship.schema.json');
        self::assertTrue((new Validator())->validate(json_decode((string) file_get_contents($this->projectRoot . '/ship.json')), $schema)->isValid());
    }

    public function test_a_bad_network_name_is_rejected(): void
    {
        $this->expectExceptionMessage('"externalNetwork" must be a Docker network name, or a list of them');

        $this->config(['proxy', '-bad']);
    }

    /**
     * Every network is attached to the app and to every service that receives HTTP; the first
     * keeps the key "external" it always had.
     */
    public function test_every_network_reaches_the_app_and_the_http_services(): void
    {
        $compose = Yaml::parse((new ComposeFileBuilder(new ServiceRegistry([new ReverbService()])))->build($this->config(['proxy', 'monitoring']), ShipEnvironment::Production));

        self::assertSame(['name' => 'proxy', 'external' => true], $compose['networks']['external']);
        self::assertSame(['name' => 'monitoring', 'external' => true], $compose['networks']['external-2']);

        foreach (['app', 'webserver', 'reverb'] as $service) {
            self::assertContains('external', $compose['services'][$service]['networks'], $service);
            self::assertContains('external-2', $compose['services'][$service]['networks'], $service);
        }
    }

    public function test_init_takes_comma_separated_names_and_keeps_them_on_a_re_run(): void
    {
        $run = function (array $inputs): CommandTester {
            $tester = new CommandTester(new InitCommand($this->projectRoot, new ServiceRegistry([]), new PackageManagerVersions(static fn (string $url): ?string => null)));
            $tester->setInputs($inputs);
            $tester->execute([]);

            return $tester;
        };

        // Name, no processes, the networks (a bad one first), PHP, Node.
        $tester = $run(['', 'no', 'proxy, bad name', ' proxy , monitoring,proxy ', '8.4', '']);
        self::assertStringContainsString('"bad name" isn\'t a Docker network name', $tester->getDisplay());
        self::assertSame(['proxy', 'monitoring'], $this->written());

        // Enter keeps them.
        $run(['', 'no', '', '8.4', '']);
        self::assertSame(['proxy', 'monitoring'], $this->written());
    }
}
