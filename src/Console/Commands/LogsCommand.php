<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'logs', description: 'Tail logs for one service, or all services if none given')]
final class LogsCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('service', InputArgument::OPTIONAL, 'Compose service name; omit for all services');
        $this->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow log output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $command = [...ComposeCommand::baseArgs($this->projectRoot), 'logs'];

        if ((bool) $input->getOption('follow')) {
            $command[] = '--follow';
        }

        $service = $input->getArgument('service');
        if (is_string($service) && $service !== '') {
            $command[] = $service;
        }

        return $this->runner->runInteractive($command, $this->projectRoot);
    }
}
