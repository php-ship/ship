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
        // No literal 'app' default -- a project can rename its own app service (ship.json's
        // appName, see ShipConfig), so a hardcoded fallback here would target a service that
        // doesn't exist there. Null means "not given", resolved against ship.json in
        // buildCommand() instead, once an actual project root is available.
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

        // Alpine-based images (everything in this stack) ship `sh`, not
        // `bash`, unless something explicitly installs it — `sh` works
        // everywhere and avoids a "bash: not found" surprise on first run.
        return [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $service, 'sh'];
    }

    /**
     * Falls back to the literal "app" whenever ship.json can't answer -- no project yet (`ship
     * shell` before `ship init`, or in a test with no fixture at all), or one that's malformed --
     * same graceful degrade Application::readExtensionClassesIfConfigured() already uses for the
     * same reason.
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
