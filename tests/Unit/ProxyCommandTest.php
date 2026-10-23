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
     * A flag meant for the proxied binary, like "-m", must reach it intact.
     */
    public function test_it_forwards_flags_meant_for_the_proxied_binary_untouched(): void
    {
        $input = new ArgvInput(['ship', 'artisan', 'make:model', 'Post', '-m']);

        $forwarded = $this->rawArgumentsAfterCommandName($input);

        self::assertSame(['make:model', 'Post', '-m'], $forwarded);
    }

    /**
     * A global option ahead of the command name is skipped (see ExecCommandTest).
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
     * Without a real ArgvInput (CommandTester's ArrayInput), ProxyCommand falls back to
     * $_SERVER['argv'].
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
     * `ship composer require foo/bar` runs Composer inside the app container, not on the host.
     */
    public function test_ship_composer_runs_composer_inside_the_app_service(): void
    {
        $command = $this->buildCommand(
            new ProxyCommand('composer', 'app', 'composer', $this->projectRoot(), new ProcessRunner()),
            new ArgvInput(['ship', 'composer', 'require', 'foo/bar']),
        );

        self::assertSame(
            [...$this->execPrefix(), 'app', 'composer', 'require', 'foo/bar'],
            $command,
        );
    }

    /**
     * Composer's own flags ("--dev", "-W", "--no-interaction") must not be parsed as options on
     * `ship`.
     */
    public function test_ship_composer_forwards_composers_own_flags_untouched(): void
    {
        $command = $this->buildCommand(
            new ProxyCommand('composer', 'app', 'composer', $this->projectRoot(), new ProcessRunner()),
            new ArgvInput(['ship', 'composer', 'require', '--dev', 'phpunit/phpunit', '-W', '--no-interaction']),
        );

        self::assertSame(
            [...$this->execPrefix(), 'app', 'composer', 'require', '--dev', 'phpunit/phpunit', '-W', '--no-interaction'],
            $command,
        );
    }

    /**
     * Bare `ship composer` runs bare `composer` (its own command list), not an error from ship.
     */
    public function test_bare_ship_composer_runs_bare_composer(): void
    {
        $command = $this->buildCommand(
            new ProxyCommand('composer', 'app', 'composer', $this->projectRoot(), new ProcessRunner()),
            new ArgvInput(['ship', 'composer']),
        );

        self::assertSame([...$this->execPrefix(), 'app', 'composer'], $command);
    }

    /**
     * A multi-word binary ("php artisan") is split into separate argv entries, not passed as one
     * "php artisan" executable name.
     */
    public function test_a_multi_word_binary_is_split_into_separate_arguments(): void
    {
        $command = $this->buildCommand(
            new ProxyCommand('artisan', 'app', 'php artisan', $this->projectRoot(), new ProcessRunner()),
            new ArgvInput(['ship', 'artisan', 'migrate', '--force']),
        );

        self::assertSame([...$this->execPrefix(), 'app', 'php', 'artisan', 'migrate', '--force'], $command);
    }

    public function test_it_targets_the_service_it_was_built_for(): void
    {
        $command = $this->buildCommand(
            new ProxyCommand('composer', 'client-app', 'composer', $this->projectRoot(), new ProcessRunner()),
            new ArgvInput(['ship', 'composer', 'install']),
        );

        self::assertSame([...$this->execPrefix(), 'client-app', 'composer', 'install'], $command);
    }

    private function projectRoot(): string
    {
        return sys_get_temp_dir();
    }

    /**
     * @return list<string>
     */
    private function execPrefix(): array
    {
        return [
            'docker', 'compose',
            '-f', $this->projectRoot() . '/ship/docker-compose.generated.yml',
            '--project-directory', $this->projectRoot(),
            'exec',
        ];
    }

    /**
     * @return list<string>
     */
    private function buildCommand(ProxyCommand $command, InputInterface $input): array
    {
        $method = new \ReflectionMethod($command, 'buildCommand');

        /** @var list<string> */
        return $method->invoke($command, $input);
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
