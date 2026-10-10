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
        // Declared for --help only. Forwarding reads raw argv (see execute()), so flags meant
        // for the proxied binary (`-m` in `ship artisan make:model Post -m`) pass through.
        $this->addArgument('args', InputArgument::IS_ARRAY, 'Arguments forwarded as-is');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $forwarded = $this->rawArgumentsAfterCommandName($input);
        $binaryParts = explode(' ', $this->binary);

        return $this->runner->runInteractive(
            [...ComposeCommand::execPrefix($this->projectRoot, $this->service), $this->service, ...$binaryParts, ...$forwarded],
            $this->projectRoot,
        );
    }

    /**
     * Everything typed after `ship <name>`, as typed.
     *
     * ArgvInput::getRawTokens(strip: true) skips any global option and its value ahead of the
     * command name. The $_SERVER['argv'] fallback is only reached without a real ArgvInput
     * (CommandTester).
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
