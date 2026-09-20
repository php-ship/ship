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
        // Declared for --help output only. Actual values are read from raw argv (see execute()),
        // the same way ProxyCommand does, so a flag meant for the executed command (e.g. the
        // "--force" in `ship exec app php artisan migrate --force`) isn't swallowed by Console's
        // own option parser -- it would otherwise be interpreted as an option on `ship` itself.
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
            [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $service, ...$command],
            $this->projectRoot,
        );
    }

    /**
     * Everything typed after `ship exec`, exactly as typed -- the first token is the service,
     * everything after that is the command to run, with no option/flag interpretation applied.
     *
     * A real `ship exec ...` always hands execute() a genuine ArgvInput, already bound (in
     * Command::run(), before execute() ever runs) to the full Application+Command definition --
     * so ArgvInput::getRawTokens(strip: true), which resolves the split point via
     * getFirstArgument(), correctly skips any global option and its value ahead of "exec" in
     * argv, unlike array_search($this->getName(), $argv), which took the first literal match
     * anywhere, global option value or not. Doesn't (can't, short of reimplementing
     * getFirstArgument()'s own scan by hand) tell apart a value some earlier option took from an
     * *identical-looking* "exec" -- getRawTokens() re-finds its split point by string equality,
     * not the position getFirstArgument() actually resolved -- but `ship` defines no such global
     * option today (see docs/roadmap.md). Only reached without a real ArgvInput -- CommandTester's
     * ArrayInput in tests, most notably, which can't represent "unparsed" arguments at all.
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
