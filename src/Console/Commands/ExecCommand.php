<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The generic escape hatch every framework-specific shortcut (artisan, composer, npm) wraps around.
 */
#[AsCommand(name: 'exec', description: 'Run a command inside a running service container')]
final class ExecCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
        $this->ignoreValidationErrors();
    }

    protected function configure(): void
    {
        // Declared for --help only. Values are read from raw argv (see execute()), so a flag
        // meant for the executed command (`--force` in `ship exec app php artisan migrate
        // --force`) isn't parsed as an option on `ship`.
        $this->addArgument('service', InputArgument::OPTIONAL, 'Compose service name, e.g. app');
        $this->addArgument('args', InputArgument::IS_ARRAY, 'Command to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$service, $command] = $this->rawServiceAndCommand($input);

        if ($service === null || $command === []) {
            $output->writeln('<error>Usage: ship exec <service> <command>...</error>');

            return Command::FAILURE;
        }

        return $this->runner->runInteractive(
            [...ComposeCommand::execPrefix($this->projectRoot, $service), $service, ...$command],
            $this->projectRoot,
        );
    }

    /**
     * Everything typed after `ship exec`, as typed: the first token is the service, the rest is
     * the command.
     *
     * ArgvInput::getRawTokens(strip: true) skips any global option and its value ahead of "exec".
     * The $_SERVER['argv'] fallback is only reached without a real ArgvInput (CommandTester).
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function rawServiceAndCommand(InputInterface $input): array
    {
        $rest = $input instanceof ArgvInput
            ? $input->getRawTokens(strip: true)
            : $this->rawArgvFallback();

        return [$rest[0] ?? null, array_slice($rest, 1)];
    }

    /**
     * @return list<string>
     */
    private function rawArgvFallback(): array
    {
        /** @var list<string> $argv */
        $argv = $_SERVER['argv'] ?? [];
        $position = array_search($this->getName(), $argv, strict: true);

        return $position === false ? [] : array_slice($argv, $position + 1);
    }
}
