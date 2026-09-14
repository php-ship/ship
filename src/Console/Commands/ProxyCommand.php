<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Command\Command;
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
        $forwarded = $this->rawArgumentsAfterCommandName();
        $binaryParts = explode(' ', $this->binary);

        return $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $this->service, ...$binaryParts, ...$forwarded],
            $this->projectRoot,
        );
    }

    /**
     * Everything typed after `ship <name>`, exactly as typed, with no option/flag interpretation applied.
     *
     * @return list<string>
     */
    private function rawArgumentsAfterCommandName(): array
    {
        /** @var list<string> $argv */
        $argv = $_SERVER['argv'] ?? [];
        $position = array_search($this->getName(), $argv, strict: true);

        if ($position === false) {
            return [];
        }

        return array_slice($argv, $position + 1);
    }
}
