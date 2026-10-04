<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\EnvFile;
use Ship\Docker\MySqlUsernameGuard;
use Ship\Docker\NginxUpstreamMismatch;
use Ship\Docker\ProjectName;
use Ship\Docker\RequiredEnv;
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

        // Checked independently, up front, rather than solely by attempting the real build()
        // below -- ComposeFileBuilder::build() throws on the *first* problem it hits, so an
        // unregistered service key would otherwise hide a bad `processes` name behind it (or vice
        // versa), contradicting this command's own "reports everything in one pass" promise. Both
        // checks are environment-independent (a key is either registered or not; a name is either
        // shaped right or not, regardless of dev/prod), so one pass here covers both builds below
        // at once.
        $problemsBeforeBuild = $this->problems;
        $this->checkServiceKeysAreRegistered($registry, $config, $output);
        $this->checkProcessNames($config, $output);

        // Skipped, not attempted anyway, once the checks above already found something -- both
        // builds would only re-throw on the exact same already-reported problem (ComposeFileBuilder
        // has no other failure mode -- confirmed by reading its own source), double-reporting one
        // real issue as two. The one thing this trades away is checkRequiredProductionEnv() below,
        // which needs the actual generated compose string -- a secondary check, reasonably skipped
        // until the structural problem above is fixed first.
        $prodCompose = null;
        if ($this->problems === $problemsBeforeBuild) {
            $this->buildCompose($registry, $config, ShipEnvironment::Development, 'development', $output);
            $prodCompose = $this->buildCompose($registry, $config, ShipEnvironment::Production, 'production', $output);
        }

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
     * Every value under ship.json's "services" and every "additionalServices" entry's "service"
     * -- whichever group it's under, built-in or from an extension -- has to resolve to something
     * $registry actually has, or ComposeFileBuilder::applyService() throws a raw
     * OutOfBoundsException the moment it's reached. Checked directly against $registry (not by
     * attempting a build) so every bad key is reported, not just the first one build() happens
     * to reach first.
     */
    private function checkServiceKeysAreRegistered(ServiceRegistry $registry, ShipConfig $config, OutputInterface $output): void
    {
        $keys = array_values($config->services);

        foreach ($config->additionalServices as $additional) {
            $keys[] = $additional['service'];
        }

        foreach (array_unique($keys) as $key) {
            if (!$registry->has($key)) {
                $this->problems++;
                $output->writeln(sprintf(
                    '<error>ship.json: "%s" is not a registered service -- check ship.json\'s services/'
                        . 'additionalServices for a typo, or that the extension providing it is actually '
                        . 'listed in ship.json\'s extensions.</error>',
                    $key,
                ));
            }
        }
    }

    /**
     * Same regex ComposeFileBuilder::addProcessServices() itself enforces (a `processes` name
     * becomes a compose service name, production only) -- checked here directly so every bad
     * name is reported, not just the first one that method happens to reach first. Its own
     * second check (a name colliding with a service ship already generates) is left to the real
     * build below: which names collide depends on what else ship.json selects, not just the name
     * itself, so it isn't something this can check in isolation the same way.
     */
    private function checkProcessNames(ShipConfig $config, OutputInterface $output): void
    {
        foreach ($config->processes as $name => $command) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
                $this->problems++;
                $output->writeln(sprintf(
                    '<error>ship.json processes: "%s" is not a valid name -- it becomes a compose '
                        . 'service name, so lowercase letters, digits, "-" and "_" only, starting with a '
                        . 'letter.</error>',
                    $name,
                ));
            }
        }
    }

    /**
     * Pure PHP, no Docker involved -- the exact same build this environment's real `ship up`/
     * `ship build`/`ship release` would run. By this point, the only known failure modes
     * (checkServiceKeysAreRegistered()/checkProcessNames() above) have already been ruled out, so
     * this is a safety net for anything else ComposeFileBuilder (or a service's own
     * composeFragment()) might still throw, not the primary way either of those two is detected.
     */
    private function buildCompose(
        ServiceRegistry $registry,
        ShipConfig $config,
        ShipEnvironment $environment,
        string $label,
        OutputInterface $output,
    ): ?string {
        try {
            return (new ComposeFileBuilder($registry))->build(
                $config,
                $environment,
                projectName: ProjectName::resolve($config, $this->projectRoot),
            );
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
     * This simulates what `docker compose` itself would refuse to do at that point, without
     * needing Docker to find out -- `ship build`/`ship release` run the same check (see
     * RequiredEnv::missingFrom()) before ever invoking Docker too, not just this command.
     */
    private function checkRequiredProductionEnv(string $compose, OutputInterface $output): void
    {
        $missing = RequiredEnv::missingFrom($compose, EnvFile::parse($this->projectRoot . '/.env.production'));

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
