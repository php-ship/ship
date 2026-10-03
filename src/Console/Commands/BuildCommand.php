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

    /**
     * $registry/$frameworkAdapters, when given (Application passes its own already-populated
     * ones), are used as-is instead of this command loading every extension class all over
     * again -- see UpCommand's matching constructor docblock for why. Left optional so
     * constructing this directly -- every existing test does -- still works unchanged.
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

        // The prod-nginx image builds from this exact same published file -- a stale upstream
        // breaks it here too, not just in dev (see NginxUpstreamMismatch's own docblock).
        $nginxWarning = NginxUpstreamMismatch::warning($this->projectRoot, $config);
        if ($nginxWarning !== null) {
            $output->writeln($nginxWarning);
        }

        if ($this->registry !== null && $this->frameworkAdapters !== null) {
            $registry = $this->registry;
            $frameworkAdapters = $this->frameworkAdapters;
        } else {
            $registry = new ServiceRegistry(ServiceRegistry::defaults());
            // Not printing these warnings here — Application's own constructor
            // already surfaced them once; see UpCommand's own docblock for why.
            $loaded = (new ExtensionLoader())->load($config->extensions, $registry);
            $frameworkAdapters = $loaded['frameworkAdapters'];
        }

        $projectName = ProjectName::resolve($config, $this->projectRoot);

        // Found here, before Docker is ever invoked, not left to surface as a raw `docker compose
        // build` interpolation error once ProductionBuildRunner actually runs -- same check
        // ConfigTestCommand's own dry run already makes (see RequiredEnv::missingFrom()). Leniently
        // reads .env.production (EnvFile::parse() returns [] if it doesn't exist at all) rather
        // than requiring the file outright -- unlike `ship release`, a project with no credentialed
        // service selected has nothing that needs one, so `ship build` doesn't force it to exist.
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
