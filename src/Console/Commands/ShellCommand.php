<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shell', description: 'Open an interactive shell inside a service (default: app)')]
final class ShellCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('service', InputArgument::OPTIONAL, 'Compose service name', 'app');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = (string) $input->getArgument('service');

        // Alpine-based images (everything in this stack) ship `sh`, not
        // `bash`, unless something explicitly installs it — `sh` works
        // everywhere and avoids a "bash: not found" surprise on first run.
        return $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $service, 'sh'],
            $this->projectRoot,
        );
    }
}
