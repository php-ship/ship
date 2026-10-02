<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Docker\ProductionBuildRunner;
use Ship\Docker\ProjectName;
use Ship\Extensions\ExtensionLoader;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds every project-owned production image (see ProductionImagePlan), tagged under a fixed local
 * name -- never the release tag, since this command doesn't take one at all. Useful on its own as a
 * "does production actually build" check; `ship release --tag <tag>` runs the same build again
 * through ProductionBuildRunner with the real tag rather than reusing this command's output, so
 * there's no staleness to worry about between the two.
 */
#[AsCommand(name: 'build', description: 'Build production images')]
final class BuildCommand extends Command
{
    private const LOCAL_TAG = 'local';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');
        $registry = new ServiceRegistry(ServiceRegistry::defaults());

        $warnings = (new ExtensionLoader())->load($config->extensions, $registry);
        foreach ($warnings as $warning) {
            $output->writeln("<comment>ship: warning: {$warning}</comment>");
        }

        $result = (new ProductionBuildRunner($this->runner))->build(
            $config,
            $registry,
            $this->projectRoot,
            ProjectName::resolve($config, $this->projectRoot),
            self::LOCAL_TAG,
            $output,
        );

        return $result === null ? Command::FAILURE : Command::SUCCESS;
    }
}
