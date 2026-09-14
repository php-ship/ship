<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\ExecCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Application;

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
        $originalArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['ship', 'exec', 'app', 'php', 'artisan', 'migrate', '--force'];

        try {
            $command = new ExecCommand(sys_get_temp_dir(), new ProcessRunner());
            $method = new \ReflectionMethod($command, 'rawServiceAndCommand');
            [$service, $args] = $method->invoke($command);
        } finally {
            $_SERVER['argv'] = $originalArgv;
        }

        self::assertSame('app', $service);
        self::assertSame(['php', 'artisan', 'migrate', '--force'], $args);
    }
}
