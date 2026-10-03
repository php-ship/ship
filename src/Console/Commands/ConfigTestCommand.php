<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\EnvFile;
use Ship\Docker\MySqlUsernameGuard;
use Ship\Docker\NginxUpstreamMismatch;
use Ship\Extensions\ExtensionLoader;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ship.json`'s equivalent of `nginx -t`: checks everything that's actually checkable
 * statically -- no Docker, no containers, nothing started, stopped, or built -- and reports
 * every problem it finds in one pass, not just the first. `ship up`/`ship build`/`ship release`
 * each already run a subset of these same checks themselves, but fail fast on the first one and
 * only once something is actually being started or built; this exists so a project can ask "is
 * my config sound" at any time and see everything that's wrong at once, the same way `nginx -t`
 * does before ever touching the real server.
 */
#[AsCommand(name: 'config:test', description: 'Validate ship.json and the generated compose files without starting anything')]
final class ConfigTestCommand extends Command
{
    private int $problems = 0;

    /**
     * $registry, when given (Application passes its own already-populated one), is used as-is --
     * see UpCommand's matching constructor docblock for why. Left optional so constructing this
     * directly -- every test does -- still works unchanged.
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?ServiceRegistry $registry = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->problems = 0;

        try {
            $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');
        } catch (\Throwable $e) {
            // Nothing else here is checkable without a valid config to check -- unlike every
            // other problem below, this one alone is worth failing fast on.
            $output->writeln("<error>ship.json: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        if ($this->registry !== null) {
            $registry = $this->registry;
        } else {
            $registry = new ServiceRegistry(ServiceRegistry::defaults());
            (new ExtensionLoader())->load($config->extensions, $registry);
        }

        $this->buildCompose($registry, $config, ShipEnvironment::Development, 'development', $output);
        $prodCompose = $this->buildCompose($registry, $config, ShipEnvironment::Production, 'production', $output);

        if ($prodCompose !== null) {
            $this->checkRequiredProductionEnv($prodCompose, $output);
        }

        // A warning, not a problem -- same as `ship up`/`ship build`/`ship release` themselves
        // (see NginxUpstreamMismatch's own docblock for why this never fails anything outright).
        $nginxWarning = NginxUpstreamMismatch::warning($this->projectRoot, $config);
        if ($nginxWarning !== null) {
            $output->writeln($nginxWarning);
        }

        $this->checkMySqlUsername($config, $this->projectRoot . '/.env', 'development', $output);
        $this->checkMySqlUsername($config, $this->projectRoot . '/.env.production', 'production', $output);

        if ($this->problems === 0) {
            $output->writeln('<info>ship: ship.json and the generated compose files look sound.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>ship: %d problem%s found.</error>',
            $this->problems,
            $this->problems === 1 ? '' : 's',
        ));

        return Command::FAILURE;
    }

    /**
     * Pure PHP, no Docker involved -- the exact same build this environment's real `ship up`/
     * `ship build`/`ship release` would run, so anything it throws (an unregistered service key
     * in ship.json's services, an invalid `processes` name, ...) is exactly what that real
     * command would have thrown too, just caught here before anything is actually started or
     * built.
     */
    private function buildCompose(
        ServiceRegistry $registry,
        ShipConfig $config,
        ShipEnvironment $environment,
        string $label,
        OutputInterface $output,
    ): ?string {
        try {
            return (new ComposeFileBuilder($registry))->build($config, $environment);
        } catch (\Throwable $e) {
            $this->problems++;
            $output->writeln("<error>ship.json ({$label}): {$e->getMessage()}</error>");

            return null;
        }
    }

    /**
     * ComposeFileBuilder only ever emits the `${VAR:?message}` *expression*, never the actual
     * resolved value, which only exists once a real .env.production is read (see RequiredEnv's
     * own docblock) -- so a missing credential is still valid YAML and never surfaces on its own.
     * `ship build` has no upfront check for this at all; `ship release` only checks that
     * .env.production exists, not that every variable Compose will actually require is in it.
     * This simulates what `docker compose` itself would refuse to do at that point, without
     * needing Docker to find out.
     */
    private function checkRequiredProductionEnv(string $compose, OutputInterface $output): void
    {
        if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):\?/', $compose, $matches) === 0) {
            return;
        }

        $env = EnvFile::parse($this->projectRoot . '/.env.production');
        $missing = [];

        foreach (array_unique($matches[1]) as $var) {
            if (($env[$var] ?? '') === '') {
                $missing[] = $var;
            }
        }

        if ($missing === []) {
            return;
        }

        $this->problems++;
        $output->writeln(sprintf(
            '<error>ship.json (production): %s %s required but not set in .env.production -- '
                . '`docker compose build`/`up` will refuse to run at all until %s.</error>',
            implode(', ', $missing),
            count($missing) === 1 ? 'is' : 'are',
            count($missing) === 1 ? 'it is' : 'they are',
        ));
    }

    private function checkMySqlUsername(ShipConfig $config, string $envPath, string $label, OutputInterface $output): void
    {
        if (!is_file($envPath)) {
            return;
        }

        $problems = MySqlUsernameGuard::problems($config, EnvFile::parse($envPath));

        if ($problems === []) {
            return;
        }

        $this->problems++;
        $output->writeln(sprintf(
            '<error>ship.json (%s): %s is set to "root" -- the official mysql image\'s own '
                . 'entrypoint refuses to start at all with that value (reserved for '
                . 'MYSQL_ROOT_PASSWORD, not MYSQL_USER).</error>',
            $label,
            implode(', ', $problems),
        ));
    }
}
