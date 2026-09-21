<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\LogsCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Input\ArrayInput;

final class LogsCommandTest extends TestCase
{
    public function test_it_tails_every_service_when_none_is_given(): void
    {
        $command = $this->buildCommand([]);

        self::assertSame(['docker', 'compose', '-f', $this->composePath(), '--project-directory', $this->projectRoot(), 'logs'], $command);
    }

    public function test_it_targets_one_service_when_given(): void
    {
        $command = $this->buildCommand(['service' => 'app']);

        self::assertSame('app', $command[array_key_last($command)]);
    }

    public function test_it_appends_follow_when_the_option_is_passed(): void
    {
        $command = $this->buildCommand(['--follow' => true]);

        self::assertContains('--follow', $command);
    }

    public function test_follow_and_a_service_argument_combine_in_the_right_order(): void
    {
        $command = $this->buildCommand(['service' => 'app', '--follow' => true]);

        self::assertSame(['docker', 'compose', '-f', $this->composePath(), '--project-directory', $this->projectRoot(), 'logs', '--follow', 'app'], $command);
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
        $command = new LogsCommand($this->projectRoot(), new ProcessRunner());
        $input = new ArrayInput($options, $command->getDefinition());

        $method = new \ReflectionMethod($command, 'buildCommand');

        /** @var list<string> */
        return $method->invoke($command, $input);
    }
}
