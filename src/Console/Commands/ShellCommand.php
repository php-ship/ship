<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shell', description: 'Open an interactive shell inside a service (default: the app service)')]
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
        // No literal 'app' default: the app service can be renamed (ship.json's serviceNames),
        // so an omitted argument is resolved in buildCommand().
        $this->addArgument('service', InputArgument::OPTIONAL, 'Compose service name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runner->runInteractive($this->buildCommand($input), $this->projectRoot);
    }

    /**
     * @return list<string>
     */
    private function buildCommand(InputInterface $input): array
    {
        /** @var string|null $service */
        $service = $input->getArgument('service');
        $service ??= $this->defaultAppServiceName();

        // `sh`, not `bash`: the Alpine-based images don't ship bash.
        return [...ComposeCommand::execPrefix($this->projectRoot, $service), $service, 'sh'];
    }

    /**
     * Falls back to "app" when ship.json is missing or malformed.
     */
    private function defaultAppServiceName(): string
    {
        try {
            return ShipConfig::fromFile($this->projectRoot . '/ship.json')->serviceNames['app'] ?? 'app';
        } catch (\Throwable) {
            return 'app';
        }
    }
}
