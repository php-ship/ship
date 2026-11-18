<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeCommand;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\EnvFile;
use Ship\Docker\MySqlUsernameGuard;
use Ship\Docker\NginxUpstreamMismatch;
use Ship\Docker\ProductionBuildRunner;
use Ship\Docker\ProjectName;
use Ship\Docker\ReleaseDocker;
use Ship\Docker\ReleaseFailed;
use Ship\Docker\ReleaseWriter;
use Ship\Docker\RequiredEnv;
use Ship\Extensions\ExtensionLoader;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Produces the portable release under `dist/ship/<tag>/`, the "ship-release" contract
 * (docs/release-contract.md): validates the project, builds the production images, and hands off
 * to ReleaseWriter.
 */
#[AsCommand(name: 'release', description: 'Build the portable production release artifact')]
final class ReleaseCommand extends Command
{
    /**
     * Files ship writes into the project on every run, relative to the project root.
     */
    private const GENERATED_FILES = [
        'ship/docker-compose.generated.yml',
        ComposeCommand::PRODUCTION_COMPOSE_FILE,
        'ship/prod/entrypoint.sh',
    ];

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
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing release with the same tag');
        $this->addOption('no-env', null, InputOption::VALUE_NONE, 'Don\'t copy .env.production into the release (for platforms that keep settings themselves)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = $this->resolveTag($input, $output);
        if ($tag === null) {
            return Command::FAILURE;
        }

        $force = $input->getOption('force') === true;
        $withEnv = $input->getOption('no-env') !== true;

        $envProductionPath = $this->projectRoot . '/.env.production';
        if (!is_file($envProductionPath)) {
            $output->writeln(
                '<error>ship: no .env.production found at the project root -- create one with your '
                    . 'production credentials before releasing (see README\'s "Production releases" section).</error>',
            );

            return Command::FAILURE;
        }

        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');

        // release.json's `app`, and the Compose project name on the server, which prefixes the data
        // volumes: it has to be the same on every machine that releases the project.
        if ($config->name === null) {
            $current = ProjectName::resolve($config, $this->projectRoot);
            $output->writeln(sprintf(
                '<error>ship: ship.json has no "name" -- a release needs one, so every release of this project '
                    . 'gets the same name wherever it is built. Add "name": "%s" (what this folder resolves to '
                    . 'today) to ship.json. If this project is already deployed, keep the name it was deployed '
                    . 'under: a different one means new, empty data volumes on the server.</error>',
                $current,
            ));

            return Command::FAILURE;
        }

        // Checked before the build, which takes minutes.
        try {
            ReleaseWriter::destination($this->projectRoot, $tag, $force);
        } catch (ReleaseFailed $e) {
            $output->writeln("<error>ship: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        // The prod-nginx image builds from this published file, so a stale upstream breaks it.
        $nginxWarning = NginxUpstreamMismatch::warning($this->projectRoot, $config);
        if ($nginxWarning !== null) {
            $output->writeln($nginxWarning);
        }

        $productionEnv = EnvFile::parse($envProductionPath);

        $usernameProblems = MySqlUsernameGuard::problems($config, $productionEnv);
        if ($usernameProblems !== []) {
            $output->writeln(sprintf(
                '<error>ship: %s is set to "root" in .env.production -- the official mysql and mariadb '
                    . 'images\' own entrypoints refuse to start at all with that value (it\'s reserved for '
                    . 'the root password variable, not the app user). Pick a different username.</error>',
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
        $framework = $this->describingFramework($frameworkAdapters);

        // Fail on a missing required setting before Docker is invoked: ship's own credentials (see
        // RequiredEnv::missingFrom()) and what the framework can't run without.
        $prodCompose = (new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Production, projectName: $projectName);
        $missingEnv = array_values(array_unique([
            ...RequiredEnv::missingFrom($prodCompose, $productionEnv),
            ...array_filter(
                $framework?->requiredEnv($this->projectRoot) ?? [],
                static fn (string $key): bool => ($productionEnv[$key] ?? '') === '',
            ),
        ]));
        if ($missingEnv !== []) {
            $output->writeln(sprintf(
                '<error>ship: %s %s required but not set in .env.production -- the release can\'t start '
                    . 'until %s.</error>',
                implode(', ', $missingEnv),
                count($missingEnv) === 1 ? 'is' : 'are',
                count($missingEnv) === 1 ? 'it is' : 'they are',
            ));

            return Command::FAILURE;
        }

        // Before the build, which writes generated files into the project.
        $source = $this->source();

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

        try {
            (new ReleaseWriter(new ReleaseDocker($this->runner, $this->projectRoot), $registry))->write(
                projectRoot: $this->projectRoot,
                tag: $tag,
                config: $config,
                projectName: $projectName,
                productionComposeYaml: (string) file_get_contents($this->projectRoot . '/' . ComposeCommand::PRODUCTION_COMPOSE_FILE),
                images: $result['images'],
                source: $source,
                framework: $framework,
                force: $force,
                withEnv: $withEnv,
                output: $output,
            );
        } catch (ReleaseFailed $e) {
            $output->writeln("<error>ship: {$e->getMessage()} No release was written.</error>");

            return Command::FAILURE;
        }

        $windowsWarning = $this->windowsExecutableBitWarning($config);
        if ($windowsWarning !== null) {
            $output->writeln($windowsWarning);
        }

        $output->writeln("<info>ship: release ready at dist/ship/{$tag}</info>");

        return Command::SUCCESS;
    }

    /**
     * The first framework adapter that matches the project and can describe it (see
     * DescribesRelease), the same detection ProductionBuildRunner does.
     *
     * @param list<FrameworkAdapter> $extensionAdapters
     */
    private function describingFramework(array $extensionAdapters): ?DescribesRelease
    {
        foreach ([new LaravelAdapter(), new SymfonyAdapter(), ...$extensionAdapters] as $adapter) {
            if ($adapter instanceof DescribesRelease && $adapter->detect($this->projectRoot)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * release.json's `source`: the commit, and whether the work tree had uncommitted changes. Both
     * null outside a git repository, or without git.
     *
     * The files ship regenerates on every `ship up`/`ship build`/`ship release` don't count: they
     * aren't source, and counting them would mark every release after the first as dirty.
     *
     * @return array{gitCommit: ?string, dirty: ?bool}
     */
    private function source(): array
    {
        $commit = $this->runner->runQuietWithResult(['git', 'rev-parse', 'HEAD'], $this->projectRoot);

        if ($commit['exitCode'] !== 0 || $commit['output'] === '') {
            return ['gitCommit' => null, 'dirty' => null];
        }

        $status = $this->runner->runQuietWithResult(
            [
                'git', 'status', '--porcelain', '--', '.',
                ...array_map(static fn (string $path): string => ":(exclude){$path}", self::GENERATED_FILES),
            ],
            $this->projectRoot,
        );

        return [
            'gitCommit' => $commit['output'],
            'dirty' => $status['exitCode'] === 0 ? $status['output'] !== '' : null,
        ];
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
