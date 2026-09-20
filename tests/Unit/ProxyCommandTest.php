<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\ProxyCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class ProxyCommandTest extends TestCase
{
    /**
     * Reads raw argv (see rawArgumentsAfterCommandName()'s docblock) precisely so a flag meant
     * for the proxied binary, like "-m" here, reaches it intact instead of Console's own option
     * parser rejecting it as unknown on `ship` itself.
     */
    public function test_it_forwards_flags_meant_for_the_proxied_binary_untouched(): void
    {
        $input = new ArgvInput(['ship', 'artisan', 'make:model', 'Post', '-m']);

        $forwarded = $this->rawArgumentsAfterCommandName($input);

        self::assertSame(['make:model', 'Post', '-m'], $forwarded);
    }

    /**
     * Mirrors ExecCommandTest's equivalent case: a global option ahead of the command name in
     * argv, taking a value unrelated to anything forwarded, resolved automatically via
     * ArgvInput::getRawTokens() without ProxyCommand needing to know that option exists.
     */
    public function test_it_resolves_the_command_correctly_past_an_earlier_global_option(): void
    {
        $definition = new InputDefinition([
            new InputOption('env', null, InputOption::VALUE_REQUIRED),
            new InputArgument('args', InputArgument::IS_ARRAY),
        ]);
        $input = new ArgvInput(['ship', '--env', 'production', 'artisan', 'make:model', 'Post', '-m']);
        try {
            $input->bind($definition);
        } catch (\Throwable) {
        }

        $forwarded = $this->rawArgumentsAfterCommandName($input);

        self::assertSame(['make:model', 'Post', '-m'], $forwarded);
    }

    /**
     * CommandTester's ArrayInput can't represent "unparsed" arguments at all -- ProxyCommand
     * falls back to a plain $_SERVER['argv'] search in that case (see
     * rawArgumentsAfterCommandName()'s docblock), which only a test drives directly, never a real
     * `ship artisan ...` invocation.
     */
    public function test_it_falls_back_to_raw_argv_without_a_real_argv_input(): void
    {
        $originalArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['ship', 'artisan', 'make:model', 'Post', '-m'];

        try {
            $forwarded = $this->rawArgumentsAfterCommandName(new ArrayInput([]));
        } finally {
            $_SERVER['argv'] = $originalArgv;
        }

        self::assertSame(['make:model', 'Post', '-m'], $forwarded);
    }

    /**
     * @return list<string>
     */
    private function rawArgumentsAfterCommandName(InputInterface $input): array
    {
        $command = new ProxyCommand('artisan', 'app', 'php artisan', sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'rawArgumentsAfterCommandName');

        /** @var list<string> */
        return $method->invoke($command, $input);
    }
}
