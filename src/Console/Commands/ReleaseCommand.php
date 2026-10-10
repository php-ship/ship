<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeCommand;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\DeployPlan;
use Ship\Docker\EnvFile;
use Ship\Docker\MySqlUsernameGuard;
use Ship\Docker\NginxUpstreamMismatch;
use Ship\Docker\ProductionBuildRunner;
use Ship\Docker\ProjectName;
use Ship\Docker\ReleaseManifest;
use Ship\Docker\RequiredEnv;
use Ship\Extensions\ExtensionLoader;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Produces the portable release artifact under `dist/ship/<tag>/`: a compose file that references
 * finished images (no `build:`), those images as `docker save` tars, `.env.production` copied to
 * `.env`, release.json, and -- when ship.json's deployCommands is set -- a deploy-commands.sh the
 * operator runs once on the server.
 */
#[AsCommand(name: 'release', description: 'Build the portable production release artifact')]
final class ReleaseCommand extends Command
{
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

    protected function configure(): void
    {
        $this->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'The release tag, e.g. 1.2.0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = $this->resolveTag($input, $output);
        if ($tag === null) {
            return Command::FAILURE;
        }

        $envProductionPath = $this->projectRoot . '/.env.production';
        if (!is_file($envProductionPath)) {
            $output->writeln(
                '<error>ship: no .env.production found at the project root -- create one with your '
                    . 'production credentials before releasing (see README\'s "Production releases" section).</error>',
            );

            return Command::FAILURE;
        }

        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');

        // The prod-nginx image builds from this published file, so a stale upstream breaks it.
        $nginxWarning = NginxUpstreamMismatch::warning($this->projectRoot, $config);
        if ($nginxWarning !== null) {
            $output->writeln($nginxWarning);
        }

        $usernameProblems = MySqlUsernameGuard::problems($config, EnvFile::parse($envProductionPath));
        if ($usernameProblems !== []) {
            $output->writeln(sprintf(
                '<error>ship: %s is set to "root" in .env.production -- the official mysql image\'s '
                    . 'own entrypoint refuses to start at all with that value (it\'s reserved for '
                    . 'MYSQL_ROOT_PASSWORD, not MYSQL_USER). Pick a different username.</error>',
                implode(', ', $usernameProblems),
            ));

            return Command::FAILURE;
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
        // RequiredEnv::missingFrom()).
        $prodCompose = (new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Production, projectName: $projectName);
        $missingEnv = RequiredEnv::missingFrom($prodCompose, EnvFile::parse($envProductionPath));
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
            $tag,
            $output,
        );

        if ($result === null) {
            return Command::FAILURE;
        }

        $releaseDir = $this->projectRoot . '/dist/ship/' . $tag;
        $filesystem = new Filesystem();
        $filesystem->remove($releaseDir);
        $filesystem->mkdir($releaseDir . '/images', 0o700);
        $filesystem->chmod($releaseDir, 0o700);

        $this->writeReleaseCompose($releaseDir);
        $this->copyProductionEnv($envProductionPath, $releaseDir);

        if (!$this->exportImages($result['images'], $releaseDir, $output)) {
            $output->writeln('<error>ship: exporting one or more images failed -- the release is incomplete, not '
                . 'written as release.json/deploy-commands.sh.</error>');

            return Command::FAILURE;
        }

        $this->writeDeployScript($config, $releaseDir);

        $manifest = ReleaseManifest::build($tag, $this->projectRoot, $this->runner, $result['images']);
        $filesystem->dumpFile($releaseDir . '/release.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $windowsWarning = $this->windowsExecutableBitWarning($config);
        if ($windowsWarning !== null) {
            $output->writeln($windowsWarning);
        }

        $output->writeln("<info>ship: release ready at dist/ship/{$tag}</info>");

        return Command::SUCCESS;
    }

    /**
     * Prompts for a tag when `--tag` wasn't given and the run is interactive; fails otherwise, so
     * a CI pipeline doesn't hang on a prompt. The tag must be a valid Docker tag component.
     */
    private function resolveTag(InputInterface $input, OutputInterface $output): ?string
    {
        /** @var ?string $tag */
        $tag = $input->getOption('tag');

        if ($tag === null) {
            if (!$this->isReallyInteractive($input)) {
                $output->writeln('<error>ship: --tag is required (non-interactive session -- not prompting).</error>');

                return null;
            }

            $io = new SymfonyStyle($input, $output);
            $tag = $io->ask('Release tag');
        }

        if ($tag === null || $tag === '' || preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $tag) !== 1) {
            $output->writeln(sprintf(
                '<error>ship: "%s" is not a valid release tag -- letters, digits, "_", "." and "-" only, '
                    . 'not starting with "." or "-".</error>',
                $tag ?? '',
            ));

            return null;
        }

        return $tag;
    }

    /**
     * `$input->isInteractive()` is only false when `-n` is passed, not when stdin isn't a tty (a
     * plain CI step), so stdin is checked too. `stream_isatty()` works on Windows, unlike
     * `posix_isatty()`.
     */
    private function isReallyInteractive(InputInterface $input): bool
    {
        return $input->isInteractive() && stream_isatty(STDIN);
    }

    /**
     * Writes the release's docker-compose.yml from ComposeCommand::PRODUCTION_COMPOSE_FILE: every
     * project-owned service gets its final `image:` tag and loses `build:`.
     */
    private function writeReleaseCompose(string $releaseDir): void
    {
        $generated = (string) file_get_contents($this->projectRoot . '/' . ComposeCommand::PRODUCTION_COMPOSE_FILE);
        /** @var array{services: array<string, array<string, mixed>>} $parsed */
        $parsed = Yaml::parse($generated);

        foreach ($parsed['services'] as $name => $service) {
            unset($parsed['services'][$name]['build']);
        }

        file_put_contents(
            $releaseDir . '/docker-compose.yml',
            Yaml::dump($parsed, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE),
        );
    }

    private function copyProductionEnv(string $source, string $releaseDir): void
    {
        $filesystem = new Filesystem();
        $destination = $releaseDir . '/.env';
        $filesystem->copy($source, $destination);
        $filesystem->chmod($destination, 0o600);
    }

    /**
     * One `docker save` per unique image (see ProductionImagePlan), not per service. Returns false
     * on the first failure, so a release never reports success with a missing or truncated tar.
     *
     * @param list<array{tag: string, canonicalService: string, members: list<string>}> $images
     */
    private function exportImages(array $images, string $releaseDir, OutputInterface $output): bool
    {
        foreach ($images as $image) {
            $path = "{$releaseDir}/images/{$image['canonicalService']}.tar";
            $output->writeln("<info>ship: exporting {$image['tag']} -> images/{$image['canonicalService']}.tar</info>");

            $result = $this->runner->runInteractive(['docker', 'save', '-o', $path, $image['tag']], $this->projectRoot);

            if ($result !== Command::SUCCESS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Written only when ship.json's deployCommands is set. A plain shell script, since the server
     * has no PHP or ship: it brings up the infrastructure services, then runs each command in a
     * one-off container of the app image.
     *
     * Infrastructure is determined from ComposeCommand::PRODUCTION_COMPOSE_FILE, which still has
     * `build:`. The release's own compose file has it stripped, which would make "app" and
     * "webserver" look like infrastructure too (see DeployPlan::infrastructureServices()).
     *
     * chmod() is a no-op on Windows; execute() warns about that.
     */
    private function writeDeployScript(ShipConfig $config, string $releaseDir): void
    {
        if ($config->deployCommands === []) {
            return;
        }

        $composeYaml = (string) file_get_contents($this->projectRoot . '/' . ComposeCommand::PRODUCTION_COMPOSE_FILE);
        $infrastructure = DeployPlan::infrastructureServices($composeYaml);
        $appService = $config->serviceNames['app'] ?? 'app';

        $lines = [
            '#!/bin/sh',
            '',
            '# Generated by `ship release --tag` -- run once per deploy, after every images/*.tar',
            '# has been loaded (`docker load -i images/<name>.tar`) and before `docker compose up',
            '# -d` starts the app services.',
            'set -e',
            '',
        ];

        if ($infrastructure !== []) {
            $lines[] = 'docker compose up -d --wait ' . implode(' ', $infrastructure);
            $lines[] = '';
        }

        foreach ($config->deployCommands as $command) {
            $escaped = str_replace("'", "'\\''", $command);
            $lines[] = "docker compose run --rm --no-deps -T {$appService} sh -c '{$escaped}'";
        }

        $lines[] = '';

        $path = $releaseDir . '/deploy-commands.sh';
        file_put_contents($path, implode("\n", $lines));
        chmod($path, 0o755);
    }

    /**
     * $osFamily is injectable so this is testable without running on Windows.
     */
    private function windowsExecutableBitWarning(ShipConfig $config, string $osFamily = PHP_OS_FAMILY): ?string
    {
        if ($config->deployCommands === [] || $osFamily !== 'Windows') {
            return null;
        }

        return '<comment>ship: deploy-commands.sh was written on Windows, which has no Unix '
            . 'executable bit for chmod() to set -- run `chmod +x deploy-commands.sh` on the '
            . 'server before `./deploy-commands.sh`, or invoke it as `sh deploy-commands.sh` '
            . 'instead.</comment>';
    }
}
