<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Docker\ComposeCommand;
use Ship\Extensions\ExtensionLoader;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db', description: "Open the selected database's interactive client shell")]
final class DbCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');
        $databaseKey = $config->services['database'] ?? null;

        if ($databaseKey === null) {
            $output->writeln('<error>No database service is selected. Run `ship init` and pick one, '
                . 'or add a "database" entry to ship.json\'s services object.</error>');

            return Command::FAILURE;
        }

        $registry = new ServiceRegistry(ServiceRegistry::defaults());
        (new ExtensionLoader())->load($config->extensions, $registry);
        $service = $registry->get($databaseKey);

        if (!$service instanceof ProvidesDatabaseShell) {
            $output->writeln(sprintf(
                '<error>"%s" doesn\'t provide a `ship db` shell.</error>',
                $databaseKey,
            ));

            return Command::FAILURE;
        }

        $shell = $service->databaseShellCommand();

        return $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $shell['service'], ...$shell['command']],
            $this->projectRoot,
        );
    }
}
