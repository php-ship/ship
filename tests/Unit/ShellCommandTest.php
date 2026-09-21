<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\ShellCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Input\ArrayInput;

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
     * Alpine images (everything in this stack) ship `sh`, not `bash` -- a regression here would
     * mean every `ship shell` fails with "bash: not found" instead of opening a shell.
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
