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
 * `ship.json`'s equivalent of `nginx -t`: runs every check that needs no Docker and reports all
 * the problems it finds in one pass. `ship up`/`build`/`release` run subsets of the same checks
 * but stop at the first failure.
 */
#[AsCommand(name: 'config:test', description: 'Validate ship.json and the generated compose files without starting anything')]
final class ConfigTestCommand extends Command
{
    private int $problems = 0;

    /**
     * $registry is the one Application already populated. Optional so the command can be
     * constructed directly (tests).
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
            // Nothing else can be checked without a valid config.
            $output->writeln("<error>ship.json: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        if ($this->registry !== null) {
            $registry = $this->registry;
        } else {
            $registry = new ServiceRegistry(ServiceRegistry::defaults());
            (new ExtensionLoader())->load($config->extensions, $registry);
        }

        // ComposeFileBuilder::build() throws on the first problem it hits, so its two known
        // failure causes are checked here first to report all of them. Both are
        // environment-independent, so one pass covers the dev and production builds.
        $problemsBeforeBuild = $this->problems;
        $this->checkServiceKeysAreRegistered($registry, $config, $output);
        $this->checkProcessNames($config, $output);

        // Skipped when the checks above found something: both builds would only re-report it.
        // That also skips checkRequiredProductionEnv(), which needs the generated compose file.
        $prodCompose = null;
        if ($this->problems === $problemsBeforeBuild) {
            $this->buildCompose($registry, $config, ShipEnvironment::Development, 'development', $output);
            $prodCompose = $this->buildCompose($registry, $config, ShipEnvironment::Production, 'production', $output);
        }

        if ($prodCompose !== null) {
            $this->checkRequiredProductionEnv($prodCompose, $output);
        }

        // A warning, not a problem, as in `ship up`/`build`/`release`.
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
     * Every service key under "services" and "additionalServices" must be registered, or
     * ComposeFileBuilder throws a raw OutOfBoundsException. Checked against the registry directly
     * so every bad key is reported.
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
     * The same name rule ComposeFileBuilder::addProcessServices() enforces, checked here so every
     * bad name is reported. A collision with a generated service depends on the rest of ship.json
     * and is left to the real build.
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
     * The same build `ship up`/`build`/`release` run. A safety net for anything
     * ComposeFileBuilder or a service's composeFragment() might still throw.
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
     * A missing `${VAR:?message}` value is still valid YAML, so this checks .env.production for
     * every required variable the way `docker compose` would (see RequiredEnv::missingFrom()).
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
