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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db', description: "Open a selected database's interactive client shell")]
final class DbCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'instance',
            InputArgument::OPTIONAL,
            'Name of an additional database instance (see ship.json\'s additionalServices) -- '
                . 'omit for the default one (ship.json\'s services.database)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');
        /** @var string|null $instanceArg */
        $instanceArg = $input->getArgument('instance');

        if ($instanceArg === null) {
            $databaseKey = $config->services['database'] ?? null;
            $instanceName = null;

            if ($databaseKey === null) {
                $output->writeln('<error>No database service is selected. Run `ship init` and pick '
                    . 'one, or add a "database" entry to ship.json\'s services object.</error>');

                return Command::FAILURE;
            }
        } else {
            $additional = $this->findAdditionalDatabase($config, $instanceArg);

            if ($additional === null) {
                $output->writeln(sprintf(
                    '<error>No database instance named "%s" -- check ship.json\'s '
                        . 'additionalServices.</error>',
                    $instanceArg,
                ));

                return Command::FAILURE;
            }

            $databaseKey = $additional['service'];
            $instanceName = $additional['name'];
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

        $shell = $service->databaseShellCommand($instanceName);
        // The generated compose file may have renamed this service (ship.json's serviceNames --
        // see ShipConfig's own docblock); $shell['service'] is always the *original* compose name,
        // since databaseShellCommand() computes it the same way composeFragment() did, with no way
        // to know about a rename that only ever happens downstream, in ComposeFileBuilder.
        $serviceName = $config->serviceNames[$shell['service']] ?? $shell['service'];

        return $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'exec', $serviceName, ...$shell['command']],
            $this->projectRoot,
        );
    }

    /**
     * @return array{group: string, service: string, name: string}|null
     */
    private function findAdditionalDatabase(ShipConfig $config, string $name): ?array
    {
        foreach ($config->additionalServices as $additional) {
            if ($additional['group'] === 'database' && $additional['name'] === $name) {
                return $additional;
            }
        }

        return null;
    }
}
