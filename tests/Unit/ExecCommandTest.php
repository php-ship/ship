<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\ExecCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Runs through a real Application where it matters: Symfony's default input definition already
 * has an argument named "command", so declaring a second one would throw a LogicException that a
 * bare CommandTester never reaches.
 */
final class ExecCommandTest extends TestCase
{
    public function test_its_argument_definition_does_not_collide_with_the_applications_own(): void
    {
        $command = new ExecCommand(sys_get_temp_dir(), new ProcessRunner());
        $command->setApplication(new Application('ship-test'));

        $command->mergeApplicationDefinition();

        self::assertTrue($command->getDefinition()->hasArgument('service'));
        self::assertTrue($command->getDefinition()->hasArgument('args'));
    }

    /**
     * A flag meant for the executed command, like "--force", must reach it intact.
     */
    public function test_it_forwards_flags_meant_for_the_executed_command_untouched(): void
    {
        $input = new ArgvInput(['ship', 'exec', 'app', 'php', 'artisan', 'migrate', '--force']);

        [$service, $args] = $this->rawServiceAndCommand($input);

        self::assertSame('app', $service);
        self::assertSame(['php', 'artisan', 'migrate', '--force'], $args);
    }

    /**
     * A global option ahead of "exec" (a hypothetical --env) is skipped by Symfony's option-aware
     * scan rather than a string search for "exec".
     */
    public function test_it_resolves_the_command_correctly_past_an_earlier_global_option(): void
    {
        $definition = new InputDefinition([
            new InputOption('env', null, InputOption::VALUE_REQUIRED),
            new InputArgument('args', InputArgument::IS_ARRAY),
        ]);
        $input = new ArgvInput(['ship', '--env', 'production', 'exec', 'app', 'php', 'artisan', 'migrate', '--force']);
        // Command::run() swallows the same validation error before execute() sees $input.
        try {
            $input->bind($definition);
        } catch (\Throwable) {
        }

        [$service, $args] = $this->rawServiceAndCommand($input);

        self::assertSame('app', $service);
        self::assertSame(['php', 'artisan', 'migrate', '--force'], $args);
    }

    /**
     * Without a real ArgvInput (CommandTester's ArrayInput), ExecCommand falls back to
     * $_SERVER['argv'].
     */
    public function test_it_falls_back_to_raw_argv_without_a_real_argv_input(): void
    {
        $originalArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['ship', 'exec', 'app', 'php', 'artisan', 'migrate', '--force'];

        try {
            [$service, $args] = $this->rawServiceAndCommand(new ArrayInput([]));
        } finally {
            $_SERVER['argv'] = $originalArgv;
        }

        self::assertSame('app', $service);
        self::assertSame(['php', 'artisan', 'migrate', '--force'], $args);
    }

    /**
     * @return array{0: ?string, 1: list<string>}
     */
    private function rawServiceAndCommand(InputInterface $input): array
    {
        $command = new ExecCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'rawServiceAndCommand');

        /** @var array{0: ?string, 1: list<string>} */
        return $method->invoke($command, $input);
    }
}
