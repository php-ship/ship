<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\EnvFile;
use Ship\Docker\NginxUpstreamMismatch;
use Ship\Docker\ProductionBuildRunner;
use Ship\Docker\ProjectName;
use Ship\Docker\RequiredEnv;
use Ship\Extensions\ExtensionLoader;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds every project-owned production image (see ProductionImagePlan) under a fixed local tag.
 * Useful as a "does production build" check; `ship release --tag` runs the same build again with
 * the real tag rather than reusing this output.
 */
#[AsCommand(name: 'build', description: 'Build production images')]
final class BuildCommand extends Command
{
    private const LOCAL_TAG = 'local';

    /**
     * $registry/$frameworkAdapters are the ones Application already populated. Optional so the
     * command can be constructed directly (tests), in which case it loads its own.
     *
     * @param list<FrameworkAdapter>|null $frameworkAdapters
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
        private readonly ?ServiceRegistry $registry = null,
        private readonly ?array $frameworkAdapters = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');

        // The prod-nginx image builds from this published file, so a stale upstream breaks it.
        $nginxWarning = NginxUpstreamMismatch::warning($this->projectRoot, $config);
        if ($nginxWarning !== null) {
            $output->writeln($nginxWarning);
        }

        if ($this->registry !== null && $this->frameworkAdapters !== null) {
            $registry = $this->registry;
            $frameworkAdapters = $this->frameworkAdapters;
        } else {
            $registry = new ServiceRegistry(ServiceRegistry::defaults());
            // Loading warnings aren't printed here; Application already did.
            $loaded = (new ExtensionLoader())->load($config->extensions, $registry);
            $frameworkAdapters = $loaded['frameworkAdapters'];
        }

        $projectName = ProjectName::resolve($config, $this->projectRoot);

        // Fail on a missing required credential before Docker is invoked (see
        // RequiredEnv::missingFrom()). .env.production is read leniently: a project with no
        // credentialed service doesn't need the file.
        $prodCompose = (new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Production, projectName: $projectName);
        $missingEnv = RequiredEnv::missingFrom($prodCompose, EnvFile::parse($this->projectRoot . '/.env.production'));
        if ($missingEnv !== []) {
            $output->writeln(sprintf(
                '<error>ship: %s %s required but not set in .env.production -- `docker compose '
                    . 'build` will refuse to run at all until %s.</error>',
                implode(', ', $missingEnv),
                count($missingEnv) === 1 ? 'is' : 'are',
                count($missingEnv) === 1 ? 'it is' : 'they are',
            ));

            return Command::FAILURE;
        }

        $result = (new ProductionBuildRunner($this->runner))->build(
            $config,
            $registry,
            $frameworkAdapters,
            $this->projectRoot,
            $projectName,
            self::LOCAL_TAG,
            $output,
        );

        return $result === null ? Command::FAILURE : Command::SUCCESS;
    }
}
