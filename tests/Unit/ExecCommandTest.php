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
 * Symfony's Application::getDefaultInputDefinition() already defines an argument named "command"
 * (its own command-name resolution), merged into every registered Command's definition via
 * Command::mergeApplicationDefinition() -- which every real `ship exec ...` invocation goes
 * through, but a bare CommandTester(new ExecCommand(...)) never exercises since it skips
 * Application entirely. A second argument also named "command" here throws
 * Symfony\Component\Console\Exception\LogicException the moment a real Application runs it.
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
     * Reads raw argv (see rawServiceAndCommand()'s docblock) precisely so a flag meant for the
     * executed command, like "--force" here, reaches it intact instead of Console's own option
     * parser rejecting it as unknown on `ship` itself.
     */
    public function test_it_forwards_flags_meant_for_the_executed_command_untouched(): void
    {
        $input = new ArgvInput(['ship', 'exec', 'app', 'php', 'artisan', 'migrate', '--force']);

        [$service, $args] = $this->rawServiceAndCommand($input);

        self::assertSame('app', $service);
        self::assertSame(['php', 'artisan', 'migrate', '--force'], $args);
    }

    /**
     * A global option ahead of "exec" in argv (modeled as a --env option ship doesn't define
     * today, to prove any that might be added later are handled automatically without touching
     * ExecCommand at all) is resolved via getFirstArgument()'s own option-aware scan, not a
     * string search for "exec" -- the case rawServiceAndCommand()'s own docblock describes.
     */
    public function test_it_resolves_the_command_correctly_past_an_earlier_global_option(): void
    {
        $definition = new InputDefinition([
            new InputOption('env', null, InputOption::VALUE_REQUIRED),
            new InputArgument('args', InputArgument::IS_ARRAY),
        ]);
        $input = new ArgvInput(['ship', '--env', 'production', 'exec', 'app', 'php', 'artisan', 'migrate', '--force']);
        // ignoreValidationErrors()-equivalent: Command::run() does the same swallow before
        // execute() ever sees $input, since "--force" isn't declared on $definition either.
        try {
            $input->bind($definition);
        } catch (\Throwable) {
        }

        [$service, $args] = $this->rawServiceAndCommand($input);

        self::assertSame('app', $service);
        self::assertSame(['php', 'artisan', 'migrate', '--force'], $args);
    }

    /**
     * CommandTester's ArrayInput can't represent "unparsed" arguments at all -- ExecCommand falls
     * back to a plain $_SERVER['argv'] search in that case (see rawServiceAndCommand()'s
     * docblock), which only a test drives directly, never a real `ship exec ...` invocation.
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
