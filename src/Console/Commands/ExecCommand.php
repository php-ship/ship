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
    }

    protected function configure(): void
    {
        $this->addArgument('service', InputArgument::REQUIRED, 'Compose service name, e.g. app');
        $this->addArgument('command', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Command to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = (string) $input->getArgument('service');
        /** @var list<string> $command */
        $command = $input->getArgument('command');

        return $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $service, ...$command],
            $this->projectRoot,
        );
    }
}
