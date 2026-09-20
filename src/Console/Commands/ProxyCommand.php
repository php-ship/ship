<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ship artisan migrate` becomes:
 *   new ProxyCommand('artisan', 'app', 'php artisan', $projectRoot, $runner)
 *
 * `ship composer require foo/bar` becomes:
 *   new ProxyCommand('composer', 'app', 'composer', $projectRoot, $runner)
 *
 * Every argument after the command name passes straight through, e.g. `make:model Post -m`'s `-m`.
 */
final class ProxyCommand extends Command
{
    public function __construct(
        string $name,
        private readonly string $service,
        private readonly string $binary,
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct($name);
        $this->setDescription("Run \"{$binary}\" inside the \"{$service}\" service");
        $this->ignoreValidationErrors();
    }

    protected function configure(): void
    {
        // Declared for --help output only. Actual forwarding reads raw
        // argv (see execute()) so Console's option parser never sees
        // flags meant for the proxied binary, e.g. the `-m` in
        // `ship artisan make:model Post -m`.
        $this->addArgument('args', InputArgument::IS_ARRAY, 'Arguments forwarded as-is');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $forwarded = $this->rawArgumentsAfterCommandName($input);
        $binaryParts = explode(' ', $this->binary);

        return $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $this->service, ...$binaryParts, ...$forwarded],
            $this->projectRoot,
        );
    }

    /**
     * Everything typed after `ship <name>`, exactly as typed, with no option/flag interpretation applied.
     *
     * A real `ship <name> ...` always hands execute() a genuine ArgvInput, already bound (in
     * Command::run(), before execute() ever runs) to the full Application+Command definition --
     * so ArgvInput::getRawTokens(strip: true), which resolves the split point via
     * getFirstArgument(), correctly skips any global option and its value ahead of the command
     * name in argv, unlike array_search($this->getName(), $argv), which took the first literal
     * match anywhere, global option value or not. Doesn't (can't, short of reimplementing
     * getFirstArgument()'s own scan by hand) tell apart a value some earlier option took from an
     * *identical-looking* command name -- getRawTokens() re-finds its split point by string
     * equality, not the position getFirstArgument() actually resolved -- but `ship` defines no
     * such global option today (see docs/roadmap.md). Only reached without a real ArgvInput --
     * CommandTester's ArrayInput in tests, most notably, which can't represent "unparsed"
     * arguments at all.
     *
     * @return list<string>
     */
    private function rawArgumentsAfterCommandName(InputInterface $input): array
    {
        if ($input instanceof ArgvInput) {
            return $input->getRawTokens(strip: true);
        }

        /** @var list<string> $argv */
        $argv = $_SERVER['argv'] ?? [];
        $position = array_search($this->getName(), $argv, strict: true);

        return $position === false ? [] : array_slice($argv, $position + 1);
    }
}
