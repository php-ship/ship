<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\ShellCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Filesystem\Filesystem;

final class ShellCommandTest extends TestCase
{
    public function test_it_defaults_to_the_app_service(): void
    {
        $command = $this->buildCommand([]);

        self::assertSame(['docker', 'compose', '-f', $this->composePath(), '--project-directory', $this->projectRoot(), 'exec', 'app', 'sh'], $command);
    }

    public function test_it_targets_a_given_service(): void
    {
        $command = $this->buildCommand(['service' => 'mysql']);

        self::assertSame(['docker', 'compose', '-f', $this->composePath(), '--project-directory', $this->projectRoot(), 'exec', 'mysql', 'sh'], $command);
    }

    /**
     * With a renamed app service (ship.json's serviceNames), `ship shell` with no argument
     * targets the renamed one.
     */
    public function test_it_defaults_to_ship_jsons_configured_app_name(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-shell-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, recursive: true);

        try {
            (new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']))
                ->toFile($projectRoot . '/ship.json');

            $command = new ShellCommand($projectRoot, new ProcessRunner());
            $input = new ArrayInput([], $command->getDefinition());
            $method = new \ReflectionMethod($command, 'buildCommand');

            /** @var list<string> $result */
            $result = $method->invoke($command, $input);

            self::assertSame('client-app', $result[array_key_last($result) - 1]);
        } finally {
            (new Filesystem())->remove($projectRoot);
        }
    }

    /**
     * The Alpine images ship `sh`, not `bash`.
     */
    public function test_it_always_runs_sh_not_bash(): void
    {
        $command = $this->buildCommand([]);

        self::assertSame('sh', $command[array_key_last($command)]);
    }

    private function projectRoot(): string
    {
        return sys_get_temp_dir();
    }

    private function composePath(): string
    {
        return $this->projectRoot() . '/ship/docker-compose.generated.yml';
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function buildCommand(array $options): array
    {
        $command = new ShellCommand($this->projectRoot(), new ProcessRunner());
        $input = new ArrayInput($options, $command->getDefinition());

        $method = new \ReflectionMethod($command, 'buildCommand');

        /** @var list<string> */
        return $method->invoke($command, $input);
    }
}
