<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Docker\DockerignoreGuard;
use Ship\Services\ServiceRegistry;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'init', description: 'Select services and generate ship.json + docker-compose.yml')]
final class InitCommand extends Command
{
    private const NONE = '__none__';

    // Groups whose services give each instance its own env var prefix and compose service name
    // (see SupportsNamedInstances). Only one runtime, testing driver, frontend tool or
    // broadcasting server makes sense per project.
    private const GROUPS_SUPPORTING_ADDITIONAL_INSTANCES = ['database', 'cache', 'storage', 'search', 'mail'];

    public function __construct(
        private readonly string          $projectRoot,
        private readonly ServiceRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ship init');

        $existing = $this->readExistingConfig();

        $selected = [];

        foreach ($this->groups() as $group => $label) {
            $options = $this->registry->inGroup($group);

            if ($options === []) {
                continue;
            }

            // Keyed by service key, so both prompt backends return the same shape.
            $choices = [self::NONE => 'None', ...array_combine(
                array_map(static fn ($s) => $s->key(), $options),
                array_map(static fn ($s) => $s->label(), $options),
            )];

            // Default to the existing selection, so re-running `ship init` and accepting every
            // prompt keeps the project's services.
            $default = $existing->services[$group] ?? self::NONE;
            if (!isset($choices[$default])) {
                $default = self::NONE;
            }

            $answer = $this->select($io, $input, "Select a {$label}", $choices, $default);

            if ($answer !== self::NONE) {
                $selected[$group] = $answer;
            }
        }

        $additionalServices = $this->promptForAdditionalInstances($io, $input, $existing->additionalServices ?? []);
        $siloImage = $this->promptForSiloImage(
            $io,
            $input,
            $selected,
            $additionalServices,
            $existing->siloImage ?? ShipConfig::SILO_IMAGE_STANDARD,
        );

        $phpVersion = $io->ask('PHP version', '8.4');
        $nodeVersion = $io->ask('Node.js version', '24');

        $config = new ShipConfig(
            phpVersion: (string) $phpVersion,
            services: $selected,
            extensions: $existing->extensions ?? [],
            nodeVersion: (string) $nodeVersion,
            additionalServices: $additionalServices,
            serviceNames: $existing->serviceNames ?? [],
            externalNetwork: $existing?->externalNetwork,
            phpExtensions: $existing->phpExtensions ?? [],
            publishPorts: $existing->publishPorts ?? false,
            deployCommands: $existing->deployCommands ?? [],
            processes: $existing->processes ?? [],
            hostUser: $existing->hostUser ?? false,
            name: $existing?->name,
            siloImage: $siloImage,
        );
        $config->toFile($this->projectRoot . '/ship.json');

        $this->publishStubs($selected, $additionalServices, $config->serviceNames['app'] ?? 'app');
        $this->warnAboutViteDevServerConfigIfNeeded($io);
        $this->warnAboutMissingReverbPackageIfNeeded($io, $selected);

        $io->success('Wrote ship.json and published the ship/ directory. Run `ship up` to build and start the environment.');

        return Command::SUCCESS;
    }

    /**
     * Reads the existing ship.json so the fields `ship init` doesn't prompt for (extensions,
     * serviceNames, externalNetwork, phpExtensions, publishPorts, deployCommands, processes,
     * hostUser, name) carry over. Uses the lenient ShipConfig::tryFromFile(), so one invalid field
     * doesn't discard the rest. Null when there is no file or it isn't valid JSON.
     */
    private function readExistingConfig(): ?ShipConfig
    {
        return ShipConfig::tryFromFile($this->projectRoot . '/ship.json');
    }

    /**
     * ship publishes Vite's dev server port but doesn't edit an existing vite.config.js. Warns only
     * when the project uses Vite and the config doesn't already look handled.
     */
    private function warnAboutViteDevServerConfigIfNeeded(SymfonyStyle $io): void
    {
        if (!$this->projectUsesVite()) {
            return;
        }

        $configPath = $this->findViteConfigFile();

        if ($configPath !== null && $this->viteConfigLooksAlreadyHandled($configPath)) {
            return;
        }

        $io->warning(sprintf(
            'This project uses Vite. Its dev server (`ship npm run dev`) needs one addition to %s '
            . 'to be reachable from your browser through Docker -- add this to the `server` option:',
            $configPath !== null ? basename($configPath) : 'vite.config.js',
        ));

        $io->writeln(<<<'JS'
                server: {
                    host: '0.0.0.0',           // bind inside the container, not just loopback
                    port: Number(process.env.VITE_PORT ?? 5173),
                    strictPort: true,          // fail fast instead of silently picking another
                                                // port -- one ship's docker-compose.yml doesn't publish
                    hmr: { host: 'localhost' }, // what the *browser* connects back to for HMR
                },
            JS);
        $io->newLine();
    }

    /**
     * The reverb service runs `php artisan reverb:start`, which doesn't exist until
     * `laravel/reverb` is installed; without it the container crash-loops.
     *
     * @param array<string, string> $selected
     */
    private function warnAboutMissingReverbPackageIfNeeded(SymfonyStyle $io, array $selected): void
    {
        if (($selected['broadcasting'] ?? null) !== 'reverb') {
            return;
        }

        if (is_dir($this->projectRoot . '/vendor/laravel/reverb')) {
            return;
        }

        $io->warning(
            'Reverb was selected but `laravel/reverb` isn\'t installed yet. The reverb container will '
            . 'fail to start until you run:'
        );
        $io->writeln('    composer require laravel/reverb');
        $io->writeln('    php artisan install:broadcasting');
        $io->newLine();
    }

    /**
     * Offers extra named instances beyond the one-per-group selection above (a second database, a
     * second Redis). Each answer becomes a ship.json `additionalServices` entry.
     *
     * $existing entries are kept as-is rather than re-prompted for; remove one by editing
     * ship.json.
     *
     * @param list<array{group: string, service: string, name: string}> $existing
     * @return list<array{group: string, service: string, name: string}>
     */
    private function promptForAdditionalInstances(SymfonyStyle $io, InputInterface $input, array $existing = []): array
    {
        $additional = $existing;
        $usedNames = array_map(static fn (array $a): string => $a['name'], $existing);

        while ($io->confirm(
            $additional === []
                ? 'Add a named additional service instance (e.g. a second database)?'
                : 'Add another one?',
            false,
        )) {
            $groupChoices = array_filter(
                self::GROUPS_SUPPORTING_ADDITIONAL_INSTANCES,
                fn (string $group): bool => $this->registry->inGroup($group) !== [],
            );

            if ($groupChoices === []) {
                break;
            }

            $group = $this->select($io, $input, 'Which kind of service?', array_combine($groupChoices, $groupChoices));
            $options = $this->registry->inGroup($group);
            $choices = array_combine(
                array_map(static fn ($s) => $s->key(), $options),
                array_map(static fn ($s) => $s->label(), $options),
            );
            $service = $this->select($io, $input, 'Which one?', $choices);

            do {
                $name = strtolower((string) $io->ask(
                    'Name this instance (used as its env var prefix and compose service suffix, '
                        . 'e.g. "analytics")',
                ));

                // The name becomes a compose service suffix and an env var prefix (see
                // SupportsNamedInstances); anything outside this charset breaks one or the other.
                if ($name === '') {
                    $io->warning('A name is required.');
                } elseif (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                    $io->warning(
                        "\"{$name}\" can only contain lowercase letters, numbers, and underscores, "
                            . 'and must start with a letter.',
                    );
                    $name = '';
                } elseif (in_array($name, $usedNames, true)) {
                    $io->warning("\"{$name}\" is already used -- pick a different name.");
                    $name = '';
                }
            } while ($name === '');

            $usedNames[] = $name;
            $additional[] = ['group' => $group, 'service' => $service, 'name' => $name];
        }

        return $additional;
    }

    /**
     * Silo's standard image doesn't run on every CPU (see ShipConfig::$siloImage), which the
     * service list alone can't convey, so whenever Silo is selected the choice is asked for
     * together with the reason. $current is kept when Silo isn't selected.
     *
     * @param array<string, string> $selected
     * @param list<array{group: string, service: string, name: string}> $additionalServices
     */
    private function promptForSiloImage(
        SymfonyStyle $io,
        InputInterface $input,
        array $selected,
        array $additionalServices,
        string $current,
    ): string {
        $siloSelected = ($selected['storage'] ?? null) === 'silo'
            || in_array('silo', array_column($additionalServices, 'service'), strict: true);

        if (!$siloSelected) {
            return $current;
        }

        $io->note([
            'Silo publishes two images of the same release. The standard one is built on RHEL 9, '
                . 'which requires a CPU with the x86-64-v2 instruction set: on an older CPU, or a '
                . 'virtual machine that exposes a generic CPU model, its container fails to start. '
                . 'The distroless one has no such requirement, but no shell either, so `ship shell` '
                . 'can\'t open one in it.',
            'Pick distroless if any machine that will run this stack (yours, a teammate\'s, the '
                . 'production server) might be affected. On Linux, check a machine with:',
            '    /lib64/ld-linux-x86-64.so.2 --help | grep x86-64-v2',
            'It prints "x86-64-v2 (supported, searched)" when the standard image will run. You can '
                . 'switch later with ship.json\'s "siloImage"; both images use the same data volume.',
        ]);

        return $this->select(
            $io,
            $input,
            'Which Silo image?',
            [
                ShipConfig::SILO_IMAGE_STANDARD => 'Standard (needs an x86-64-v2 CPU)',
                ShipConfig::SILO_IMAGE_DISTROLESS => 'Distroless (runs on older CPUs too; no shell in the container)',
            ],
            in_array($current, ShipConfig::SILO_IMAGES, true) ? $current : ShipConfig::SILO_IMAGE_STANDARD,
        );
    }

    private function projectUsesVite(): bool
    {
        $path = $this->projectRoot . '/package.json';

        if (!is_file($path)) {
            return false;
        }

        try {
            /** @var array{dependencies?: array<string,string>, devDependencies?: array<string,string>} $data */
            $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return array_key_exists('vite', $data['dependencies'] ?? [])
            || array_key_exists('vite', $data['devDependencies'] ?? []);
    }

    private function findViteConfigFile(): ?string
    {
        foreach (['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.cjs'] as $name) {
            $path = $this->projectRoot . '/' . $name;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * A plain substring check, permissive on purpose: a false "yes" is harmless.
     */
    private function viteConfigLooksAlreadyHandled(string $path): bool
    {
        return str_contains((string) file_get_contents($path), 'hmr');
    }

    /**
     * Uses Laravel Prompts' select() when usable, falling back to ChoiceQuestion.
     *
     * Prompts isn't a hard dependency because it conflicts with laravel/framework 10.17-10.24;
     * Laravel 11+ already ships it.
     *
     * @param array<string, string> $choicesByKey option key => label
     * @param ?string $default a key of $choicesByKey, or null for the first one (Prompts rejects a
     *        default that isn't one of its options).
     */
    private function select(
        SymfonyStyle $io,
        InputInterface $input,
        string $label,
        array $choicesByKey,
        ?string $default = null,
    ): string {
        $default ??= array_key_first($choicesByKey)
            ?? throw new \LogicException('select() called with no choices to pick from.');

        if ($this->canUseLaravelPromptsInteractiveUi($input)) {
            /** @var string */
            return \Laravel\Prompts\select(label: $label, options: $choicesByKey, default: $default);
        }

        // A numeric list, so the prompt shows "[1] Postgres" instead of internal service keys.
        $labels = array_values($choicesByKey);
        $keys = array_keys($choicesByKey);
        $defaultIndex = array_search($default, $keys, strict: true);

        $question = new ChoiceQuestion($label, $labels, $defaultIndex === false ? 0 : $defaultIndex);
        $question->setErrorMessage('%s is not a valid choice.');

        /** @var string $answerLabel */
        $answerLabel = $io->askQuestion($question);
        $index = array_search($answerLabel, $labels, strict: true);

        return $index === false ? $default : $keys[$index];
    }

    /**
     * Prompts' own fallback for Windows is wired up by Laravel's provider, which doesn't run when
     * Prompts is called standalone, so the same conditions are checked here.
     */
    private function canUseLaravelPromptsInteractiveUi(InputInterface $input): bool
    {
        return function_exists('Laravel\Prompts\select')
            && $input->isInteractive()
            && PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * Copies the Dockerfile, php.ini overlays and (when needed) the nginx/garage config into the
     * project's ship/ directory, and makes .dockerignore/.gitignore exclude secrets.
     *
     * @param array<string, string> $selected
     * @param list<array{group: string, service: string, name: string}> $additionalServices
     */
    private function publishStubs(array $selected, array $additionalServices, string $appServiceName): void
    {
        $filesystem = new Filesystem();
        $packageRoot = dirname(__DIR__, 3);
        $target = $this->projectRoot . '/ship';

        $filesystem->mirror($packageRoot . '/stubs/docker/php', $target, options: ['override' => true]);

        // nginx is only needed without an Octane runtime (see ComposeFileBuilder::baseServices()).
        // It builds through ship/Dockerfile, so only its config is published.
        if (! isset($selected['runtime'])) {
            $filesystem->mkdir($target . '/nginx');
            $filesystem->copy(
                $packageRoot . '/stubs/docker/nginx/default.conf',
                $target . '/nginx/default.conf',
                overwriteNewerFiles: true,
            );

            // The stub hardcodes "app:9000"; follow a renamed app service (ship.json's
            // serviceNames) or nginx would 502 every request.
            if ($appServiceName !== 'app') {
                $confPath = $target . '/nginx/default.conf';
                file_put_contents($confPath, str_replace('app:9000', "{$appServiceName}:9000", (string) file_get_contents($confPath)));
            }
        }

        // Garage needs its stub files whether it's the default storage pick or a named instance.
        $garageSelected = ($selected['storage'] ?? null) === 'garage'
            || in_array('garage', array_column($additionalServices, 'service'), strict: true);

        if ($garageSelected) {
            $filesystem->mirror(
                $packageRoot . '/stubs/docker/garage',
                $target . '/garage',
                options: ['override' => true],
            );
        }

        // Read back by UpCommand to warn when `ship` was upgraded without re-running `init`.
        // Omitted when the version can't be determined, so it can't cause a false mismatch.
        $version = ShipVersion::current();
        if ($version !== null) {
            file_put_contents($target . '/.ship-version', $version . "\n");
        }

        DockerignoreGuard::ensure($this->projectRoot);
        $this->ensureGitignoreExcludesSecrets();
    }

    /**
     * Keep the production credential source and release output out of the project's history.
     * Merge these rules into any existing .gitignore without overwriting project-owned entries.
     */
    private function ensureGitignoreExcludesSecrets(): void
    {
        $path = $this->projectRoot . '/.gitignore';
        $required = ['/.env.production', '/dist/ship/'];

        $fileLines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $existing = $fileLines === false ? [] : $fileLines;

        $missing = array_values(array_diff($required, $existing));

        if ($missing === []) {
            return;
        }

        $header = $existing === [] ? "# Added by `ship init` -- production secrets and release output.\n" : "\n# Added by `ship init`:\n";

        file_put_contents($path, $header . implode("\n", $missing) . "\n", FILE_APPEND);
    }

    /**
     * @return array<string, string>
     */
    private function groups(): array
    {
        return [
            'database' => 'database',
            'cache' => 'cache',
            'runtime' => 'application runtime',
            'storage' => 'object storage',
            'search' => 'search engine',
            'mail' => 'mail capture service',
            'testing' => 'browser testing driver',
            'frontend' => 'frontend tooling',
            'broadcasting' => 'broadcasting service',
        ];
    }
}
