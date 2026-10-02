<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Docker\DeployPlan;
use Ship\Docker\ProductionBuildRunner;
use Ship\Docker\ProjectName;
use Ship\Docker\ReleaseManifest;
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
 * Produces the portable release artifact under `dist/ship/<tag>/`: a production-ready compose file
 * that references finished images (no `build:`, no source, no project Dockerfiles), those images
 * as `docker save` tars, `.env.production` copied to `.env`, release.json metadata, and -- when
 * ship.json's deployCommands is set -- a deploy-commands.sh the operator runs once on the server.
 * The exact same artifact works for a DevOps handoff, a developer's own manual deploy, or CI/CD --
 * there's deliberately no separate format for any of the three.
 */
#[AsCommand(name: 'release', description: 'Build the portable production release artifact')]
final class ReleaseCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
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
            $tag,
            $output,
        );

        if ($result === null) {
            return Command::FAILURE;
        }

        $releaseDir = $this->projectRoot . '/dist/ship/' . $tag;
        $filesystem = new Filesystem();
        $filesystem->remove($releaseDir);
        $filesystem->mkdir($releaseDir . '/images');

        $this->writeReleaseCompose($releaseDir);
        $filesystem->copy($envProductionPath, $releaseDir . '/.env');
        $this->exportImages($result['images'], $releaseDir, $output);
        $this->writeDeployScript($config, $releaseDir);

        $manifest = ReleaseManifest::build($tag, $this->projectRoot, $this->runner, $result['images']);
        $filesystem->dumpFile($releaseDir . '/release.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $output->writeln("<info>ship: release ready at dist/ship/{$tag}</info>");

        return Command::SUCCESS;
    }

    /**
     * Interactive: prompts when `--tag` wasn't given. Non-interactive (CI): fails immediately
     * instead -- a prompt that never gets an answer would otherwise hang the pipeline, not fail it
     * loudly. Either way, the tag has to be a valid Docker tag component, since it becomes one.
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
     * `$input->isInteractive()` alone isn't enough -- Symfony only ever sets it false when
     * `--no-interaction`/`-n` is explicitly passed, never from detecting a non-tty stdin on its
     * own. A GitHub Actions `run:` step (or any other CI shell) has no tty and also passes no `-n`,
     * so trusting `isInteractive()` alone would try to `$io->ask()` against a stream nothing will
     * ever answer, hanging the pipeline instead of failing it -- confirmed live, not assumed:
     * piping from `/dev/null` with no `-n` left this printing "Release tag:" and blocking forever
     * before this check existed. `stream_isatty()` (unlike `posix_isatty`, missing on Windows)
     * works everywhere this package already claims to run.
     */
    private function isReallyInteractive(InputInterface $input): bool
    {
        return $input->isInteractive() && stream_isatty(STDIN);
    }

    /**
     * The release's own docker-compose.yml, unlike ship/docker-compose.generated.yml (which
     * ProductionBuildRunner just wrote and still needs `build:` to actually build the images):
     * every project-owned service already has its final `image:` tag, so `build:` -- and the
     * source/Dockerfile/build-context it points at -- is dropped entirely. This is the one file
     * the spec is explicit about: a runnable artifact, not a build workspace.
     */
    private function writeReleaseCompose(string $releaseDir): void
    {
        $generated = (string) file_get_contents($this->projectRoot . '/ship/docker-compose.generated.yml');
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

    /**
     * One `docker save` per unique image (see ProductionImagePlan) -- never per service, so two
     * services sharing one image (e.g. "app" and a `processes` entry) don't double the archive.
     *
     * @param list<array{tag: string, canonicalService: string, members: list<string>}> $images
     */
    private function exportImages(array $images, string $releaseDir, OutputInterface $output): void
    {
        foreach ($images as $image) {
            $path = "{$releaseDir}/images/{$image['canonicalService']}.tar";
            $output->writeln("<info>ship: exporting {$image['tag']} -> images/{$image['canonicalService']}.tar</info>");

            $this->runner->runInteractive(['docker', 'save', '-o', $path, $image['tag']], $this->projectRoot);
        }
    }

    /**
     * Only written when ship.json's deployCommands is set -- a plain shell script, not something
     * that shells out to `ship` itself, since the destination server has no PHP/ship installed at
     * all (that's the entire point of this release format). Brings up any registry-pulled
     * infrastructure first (nothing else starts it -- see DeployPlan::infrastructureServices()'s own
     * docblock), then runs each command once against a one-off container of the app's own image.
     *
     * Reads `ship/docker-compose.generated.yml` (ProductionBuildRunner's own working copy, still
     * carrying every project-owned service's `build:`), not the release's own docker-compose.yml --
     * writeReleaseCompose() strips `build:` from *every* project-owned service there, "app" and
     * "webserver" included, which would otherwise make DeployPlan::infrastructureServices() see no
     * `build:` anywhere and misclassify them as infrastructure too, defeating the entire reason this
     * runs before they start.
     */
    private function writeDeployScript(ShipConfig $config, string $releaseDir): void
    {
        if ($config->deployCommands === []) {
            return;
        }

        $composeYaml = (string) file_get_contents($this->projectRoot . '/ship/docker-compose.generated.yml');
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
}
