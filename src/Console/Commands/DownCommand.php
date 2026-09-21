<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Sync\MutagenSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'down', description: 'Stop and remove the environment')]
final class DownCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'volumes',
            null,
            InputOption::VALUE_NONE,
            'Also remove named volumes (deletes database/storage data)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Safe unconditionally, whether or not this project ever actually used Mutagen -- see
        // MutagenSync::stop()'s own docblock. Before, not after, tearing down the containers the
        // sync was targeting: it's the same order `ship up` starts them in, reversed.
        (new MutagenSync($this->runner, $this->projectRoot))->stop();

        return $this->runner->runInteractive($this->buildCommand($input), $this->projectRoot);
    }

    /**
     * @return list<string>
     */
    private function buildCommand(InputInterface $input): array
    {
        $command = [...ComposeCommand::baseArgs($this->projectRoot), 'down'];

        if ((bool) $input->getOption('volumes')) {
            $command[] = '--volumes';
        }

        return $command;
    }
}
