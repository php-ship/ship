<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Console\Ui;
use Ship\Contracts\FrameworkAdapter;
use Ship\Docker\DockerignoreGuard;
use Ship\Frameworks\Frameworks;
use Ship\Node\PackageManager;
use Ship\Node\PackageManagerVersions;
use Ship\Services\ServiceRegistry;
use Ship\Support\PhpSecurityFloor;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'init', description: 'Select services and generate ship.json + docker-compose.yml')]
final class InitCommand extends Command
{
    // Groups whose services give each instance its own env var prefix and compose service name
    // (see SupportsNamedInstances). Only one runtime, testing driver, frontend tool,
    // broadcasting server or message broker makes sense per project.
    /** The "None" choice of a service menu. */
    private const NONE = '__none__';

    private const PACKAGE_MANAGERS = ['npm' => 'npm', 'yarn' => 'Yarn', 'pnpm' => 'pnpm'];

    private const GROUPS_SUPPORTING_ADDITIONAL_INSTANCES = ['database', 'cache', 'storage', 'search', 'mail'];

    private readonly PackageManagerVersions $packageManagerVersions;

    /** @var \Closure(InputInterface, OutputInterface): Ui */
    private readonly \Closure $ui;

    /**
     * @param ?\Closure(InputInterface, OutputInterface): Ui $ui builds the interface (tests force
     *        Prompts' own, with its terminal faked)
     * @param list<FrameworkAdapter> $extensionFrameworkAdapters
     */
    public function __construct(
        private readonly string          $projectRoot,
        private readonly ServiceRegistry $registry,
        ?PackageManagerVersions $packageManagerVersions = null,
        ?\Closure $ui = null,
        private readonly array $extensionFrameworkAdapters = [],
    ) {
        parent::__construct();
        $this->packageManagerVersions = $packageManagerVersions ?? new PackageManagerVersions();
        $this->ui = $ui ?? static fn (InputInterface $input, OutputInterface $output): Ui => new Ui($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Everything `ship init` doesn't ask about (name, processes, deploy commands, ...) comes
        // from the existing ship.json. One it can't read would be replaced by a new file without
        // them, so it stops instead and leaves the file as it is.
        $path = $this->projectRoot . '/ship.json';
        if (is_file($path) && ShipConfig::tryFromFile($path) === null) {
            json_decode((string) file_get_contents($path));
            $output->writeln(sprintf(
                '<error>ship: ship.json can\'t be read (%s), so `ship init` stops rather than replace it and lose its settings. Fix it, or move it away to start over.</error>',
                json_last_error() === JSON_ERROR_NONE ? 'it isn\'t a JSON object' : json_last_error_msg(),
            ));

            return Command::FAILURE;
        }

        $ui = ($this->ui)($input, $output);
        // The questions get a clean screen; the terminal's earlier content comes back with the
        // summary below it (see Ui::enterFullScreen()).
        $ui->enterFullScreen('ship init was cancelled: nothing was written.');
        $ui->intro('ship init');

        if ($ui->rich) {
            $ui->info('Ctrl+U goes back to the previous question.');
        }

        $existing = $this->readExistingConfig();

        if ($existing !== null) {
            $ui->note('This project already has a ship.json: every question defaults to its current setting, so pressing Enter keeps it.');
        }

        // One checklist of the kinds of service the project needs, then a choice for each one
        // ticked. A kind starts ticked when the project has a service of it that's still offered,
        // so re-running `ship init` and accepting every prompt keeps the project's services.
        $offered = [];
        foreach ($this->groups() as $group => [$label]) {
            $options = $this->registry->inGroup($group);

            if ($options !== []) {
                $offered[$group] = array_combine(
                    array_map(static fn ($s) => $s->key(), $options),
                    array_map(static fn ($s) => $s->label(), $options),
                );
            }
        }

        $minimumPhp = Frameworks::minimumPhp($this->projectRoot, $this->extensionFrameworkAdapters);
        $defaultPhp = $existing->phpVersion ?? '8.4';
        if ($minimumPhp !== null && version_compare($defaultPhp, $minimumPhp['version'], '<')) {
            $defaultPhp = $minimumPhp['version'];
        }
        $packageManager = PackageManager::inspect($this->projectRoot);

        // Every question is a step of one form, so Ctrl+U goes back to the previous one, which
        // then starts at the answer given before. Steps for kinds that weren't ticked are skipped,
        // going forward and back. Nothing is written until the last answer.
        // A step that asks nothing would send Ctrl+U straight back to where it came from, so steps
        // that might not ask (no services at all, Silo not chosen) are conditional: Prompts skips
        // them both ways.
        $instanceGroups = array_values(array_intersect(self::GROUPS_SUPPORTING_ADDITIONAL_INSTANCES, array_keys($offered)));

        $form = $ui->form()
            ->addIf(
                $offered !== [],
                fn (array $answers, mixed $previous): array => $ui->multiselect(
                    'Which services does this project need?',
                    array_map(fn (string $group): string => $this->groups()[$group][0], array_combine(array_keys($offered), array_keys($offered))),
                    is_array($previous) ? array_values(array_map(strval(...), $previous)) : array_values(array_filter(
                        array_keys($offered),
                        static fn (string $group): bool => isset($offered[$group][$existing->services[$group] ?? '']),
                    )),
                    'Space ticks a kind, Enter continues; you pick the service for each next',
                ),
                name: 'kinds',
            );

        foreach ($offered as $group => $choices) {
            [$label, $hint] = $this->groups()[$group];
            $form->addIf(
                static fn (array $answers): bool => in_array($group, $answers['kinds'] ?? [], true),
                static function (array $answers, mixed $previous) use ($ui, $label, $hint, $choices, $existing, $group): string {
                    // "None" last, so a kind ticked by mistake (an Octane runtime when php-fpm
                    // will do) can still be left out; the menu starts on a real service.
                    $choices += [self::NONE => $group === 'runtime' ? 'None (PHP-FPM behind nginx)' : 'None'];
                    $current = is_string($previous) ? $previous : ($existing->services[$group] ?? null);

                    return $ui->select($label, $choices, $current !== null && isset($choices[$current]) ? $current : null, $hint);
                },
                name: "service:{$group}",
            );
        }

        $form = $form
            ->addIf(
                $instanceGroups !== [],
                // Going back to it keeps the instances added before (it then asks to add another).
                fn (array $answers, mixed $previous): array => $this->promptForAdditionalInstances(
                    $ui,
                    is_array($previous) ? ShipConfig::filterAdditionalServices($previous) : ($existing->additionalServices ?? []),
                ),
                name: 'additionalServices',
            )
            ->addIf(
                static fn (array $answers): bool => self::usesSilo(self::selectedServices($answers), is_array($answers['additionalServices'] ?? null) ? $answers['additionalServices'] : []),
                fn (array $answers, mixed $previous): string => $this->promptForSiloImage(
                    $ui,
                    is_string($previous) ? $previous : ($existing->siloImage ?? ShipConfig::SILO_IMAGE_STANDARD),
                ),
                name: 'siloImage',
            )
            // Rejects an end-of-life branch or a pinned patch release with known
            // vulnerabilities, or one older than the project's framework needs (Tempest 3: 8.5),
            // here rather than at the end of the first build.
            ->add(
                fn (array $answers, mixed $previous): string => $ui->text(
                    'PHP version',
                    default: is_string($previous) ? $previous : $defaultPhp,
                    hint: $minimumPhp !== null
                        ? "{$minimumPhp['framework']} needs {$minimumPhp['version']} or newer"
                        : 'A supported branch, such as 8.4, or an exact release',
                    validate: fn (string $answer): ?string => (preg_match(ShipConfig::PHP_VERSION_PATTERN, trim($answer)) === 1 ? null : 'Use a PHP version such as 8.4 or 8.4.12.')
                        ?? PhpSecurityFloor::problemWith(trim($answer))
                        ?? Frameworks::phpVersionProblem($this->projectRoot, $answer, $this->extensionFrameworkAdapters),
                ),
                name: 'php',
            )
            ->add(
                static fn (array $answers, mixed $previous): string => $ui->text(
                    'Node.js version',
                    default: is_string($previous) ? $previous : ($existing->nodeVersion ?? '24'),
                    hint: 'A major version, such as 24, or an exact release',
                    validate: static fn (string $answer): ?string => preg_match('/^\d+(\.\d+){0,2}$/', trim($answer)) === 1
                        ? null
                        : 'Use a Node.js version such as 24 or 24.11.',
                ),
                name: 'node',
            )
            ->addIf(
                $packageManager->hasPackageJson,
                fn (array $answers, mixed $previous): string => $this->askPackageManager($ui, $packageManager, is_string($previous) ? $previous : null),
                name: 'packageManager',
            );

        // The terminal's own screen comes back however the questions end, before an error is
        // printed (which would otherwise vanish with the alternate screen).
        try {
            $answers = $form->submit();
        } finally {
            $ui->leaveFullScreen();
        }

        if ($ui->rich) {
            $ui->intro('ship init');
        }

        $selected = self::selectedServices($answers);
        $summary = [];
        foreach ($selected as $group => $key) {
            $summary[] = [$this->groups()[$group][0], $offered[$group][$key]];
        }
        /** @var list<array{group: string, service: string, name: string}> $additionalServices */
        $additionalServices = is_array($answers['additionalServices'] ?? null) ? $answers['additionalServices'] : ($existing->additionalServices ?? []);
        $siloImage = is_string($answers['siloImage'] ?? null) ? $answers['siloImage'] : ($existing->siloImage ?? ShipConfig::SILO_IMAGE_STANDARD);
        $phpVersion = trim((string) $answers['php']);
        $nodeVersion = trim((string) $answers['node']);
        $chosenPackageManager = is_string($answers['packageManager'] ?? null) ? $answers['packageManager'] : null;

        if ($chosenPackageManager !== null) {
            $this->recordPackageManager($ui, $packageManager, $chosenPackageManager, $nodeVersion);
        }

        $config = new ShipConfig(
            phpVersion: $phpVersion,
            services: $selected,
            extensions: $existing->extensions ?? [],
            nodeVersion: $nodeVersion,
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
            processHttp: $existing->processHttp ?? [],
        );
        $config->toFile($this->projectRoot . '/ship.json');

        $this->publishStubs($selected, $additionalServices, $config->serviceNames['app'] ?? 'app');

        foreach ($additionalServices as $instance) {
            $summary[] = ["{$this->groups()[$instance['group']][0]} \"{$instance['name']}\"", $this->serviceLabel($instance['group'], $instance['service'])];
        }
        $ui->table(['Service', 'Choice'], [
            ...($summary === [] ? [['Services', 'None']] : $summary),
            ['PHP', $phpVersion],
            ['Node.js', $nodeVersion . ($chosenPackageManager !== null ? ', ' . self::PACKAGE_MANAGERS[$chosenPackageManager] : '')],
        ]);

        $this->warnAboutViteDevServerConfigIfNeeded($ui);
        $this->warnAboutMissingReverbPackageIfNeeded($ui, $selected);

        $ui->outro('Wrote ship.json and ship/. Run `ship up` to build and start it.');

        return Command::SUCCESS;
    }

    /**
     * Whether Silo is the storage service or one of the additional instances.
     *
     * @param array<string, string> $selected
     * @param array<mixed> $additionalServices
     */
    private static function usesSilo(array $selected, array $additionalServices): bool
    {
        return ($selected['storage'] ?? null) === 'silo'
            || in_array('silo', array_column($additionalServices, 'service'), strict: true);
    }

    /**
     * The service chosen for each ticked kind, from the form's answers.
     *
     * @param array<mixed> $answers
     * @return array<string, string>
     */
    private static function selectedServices(array $answers): array
    {
        $selected = [];
        foreach (is_array($answers['kinds'] ?? null) ? $answers['kinds'] : [] as $group) {
            $service = $answers["service:{$group}"] ?? null;
            if (is_string($group) && is_string($service) && $service !== self::NONE) {
                $selected[$group] = $service;
            }
        }

        return $selected;
    }

    private function serviceLabel(string $group, string $key): string
    {
        foreach ($this->registry->inGroup($group) as $service) {
            if ($service->key() === $key) {
                return $service->label();
            }
        }

        return $key;
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
    private function warnAboutViteDevServerConfigIfNeeded(Ui $ui): void
    {
        if (!$this->projectUsesVite()) {
            return;
        }

        $configPath = $this->findViteConfigFile();

        if ($configPath !== null && $this->viteConfigLooksAlreadyHandled($configPath)) {
            return;
        }

        $ui->warning(sprintf(
            'This project uses Vite. Its dev server (`ship npm run dev`) needs one addition to %s '
            . 'to be reachable from your browser through Docker -- add this to the `server` option:',
            $configPath !== null ? basename($configPath) : 'vite.config.js',
        ));

        $ui->code(<<<'JS'
            server: {
                host: '0.0.0.0',           // bind inside the container, not just loopback
                port: Number(process.env.VITE_PORT ?? 5173),
                strictPort: true,          // fail fast instead of silently picking another
                                            // port -- one ship's docker-compose.yml doesn't publish
                hmr: { host: 'localhost' }, // what the *browser* connects back to for HMR
            },
            JS);
    }

    /**
     * The reverb service runs `php artisan reverb:start`, which doesn't exist until
     * `laravel/reverb` is installed; without it the container crash-loops.
     *
     * @param array<string, string> $selected
     */
    private function warnAboutMissingReverbPackageIfNeeded(Ui $ui, array $selected): void
    {
        if (($selected['broadcasting'] ?? null) !== 'reverb') {
            return;
        }

        if (is_dir($this->projectRoot . '/vendor/laravel/reverb')) {
            return;
        }

        $ui->warning(
            'Reverb was selected but `laravel/reverb` isn\'t installed yet. The reverb container will '
            . 'fail to start until you run:'
        );
        $ui->code("ship composer require laravel/reverb\nship artisan install:broadcasting");
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
    private function promptForAdditionalInstances(Ui $ui, array $existing = []): array
    {
        $additional = $existing;
        $usedNames = array_map(static fn (array $a): string => $a['name'], $existing);

        $groupChoices = array_values(array_filter(
            self::GROUPS_SUPPORTING_ADDITIONAL_INSTANCES,
            fn (string $group): bool => $this->registry->inGroup($group) !== [],
        ));

        if ($groupChoices === []) {
            return $additional;
        }

        while ($ui->confirm(
            $additional === [] ? 'Add a second instance of a service, e.g. a second database?' : 'Add another one?',
            false,
            $additional === [] ? 'Each instance gets its own container, data volume and env var prefix' : '',
        )) {
            $group = $ui->select('Which kind of service?', array_combine(
                $groupChoices,
                array_map(fn (string $group): string => $this->groups()[$group][0], $groupChoices),
            ));
            $options = $this->registry->inGroup($group);
            $service = $ui->select('Which one?', array_combine(
                array_map(static fn ($s) => $s->key(), $options),
                array_map(static fn ($s) => $s->label(), $options),
            ));

            // The name becomes a compose service suffix and an env var prefix (see
            // SupportsNamedInstances); anything outside this charset breaks one or the other.
            $name = strtolower($ui->text(
                'Name this instance',
                placeholder: 'analytics',
                hint: 'Its env var prefix and compose service suffix',
                validate: static function (string $answer) use ($usedNames): ?string {
                    $name = strtolower(trim($answer));

                    return match (true) {
                        preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 => "\"{$name}\" can only contain lowercase letters, numbers, and underscores, and must start with a letter.",
                        in_array($name, $usedNames, true) => "\"{$name}\" is already used: pick a different name.",
                        default => null,
                    };
                },
            ));

            $usedNames[] = $name;
            $additional[] = ['group' => $group, 'service' => $service, 'name' => $name];
        }

        return $additional;
    }

    /**
     * Silo's standard image doesn't run on every CPU (see ShipConfig::$siloImage), which the
     * service list alone can't convey, so whenever Silo is selected the choice is asked for
     * together with the reason.
     */
    private function promptForSiloImage(Ui $ui, string $current): string
    {
        $ui->note(implode("\n\n", [
            'Silo publishes two images of the same release. The standard one is built on RHEL 9, '
                . 'which requires a CPU with the x86-64-v2 instruction set: on an older CPU, or a '
                . 'virtual machine that exposes a generic CPU model, its container fails to start. '
                . 'The distroless one has no such requirement, but no shell either, so `ship shell` '
                . 'can\'t open one in it.',
            'Pick distroless if any machine that will run this stack (yours, a teammate\'s, the '
                . 'production server) might be affected. On Linux, check a machine with '
                . '`/lib64/ld-linux-x86-64.so.2 --help | grep x86-64-v2`: it prints '
                . '"x86-64-v2 (supported, searched)" when the standard image will run.',
            'You can switch later with ship.json\'s "siloImage"; both images use the same data volume.',
        ]));

        return $ui->select(
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
     * Which package manager the project uses, for a project with a package.json. The default is
     * what it already uses (see PackageManager), so pressing Enter changes nothing; $previous is
     * the answer given before going back to this question.
     */
    private function askPackageManager(Ui $ui, PackageManager $current, ?string $previous): string
    {
        $default = $previous
            ?? $current->tool()
            ?? (in_array($current->declared, PackageManager::TOOLS, true) ? $current->declared : null)
            ?? ($current->lockfileTools[0] ?? 'npm');

        return $ui->select(
            'JavaScript package manager',
            self::PACKAGE_MANAGERS,
            $default,
            match (true) {
                $current->problem() !== null => 'The project has lockfiles of more than one; ship will ask you to finish the switch',
                $current->tool() !== null => "The project uses {$current->tool()} now",
                default => 'The project has no lockfile yet',
            },
        );
    }

    /**
     * Records the chosen package manager in package.json's packageManager at its current version
     * (see PackageManagerVersions: the project's own Yarn line for a Yarn 1 lockfile, and for npm
     * the npm its Node version comes with). Recording it is all a project that doesn't use another
     * tool yet needs. Moving a project off another tool also removes its lockfile and installed
     * packages and reinstalls, which needs the container, so it's left to `ship package-manager`:
     * the recorded choice makes `ship up` remind the user, and the production build refuse, until
     * it's done.
     */
    private function recordPackageManager(Ui $ui, PackageManager $current, string $choice, string $nodeVersion): void
    {
        $switching = $current->problem() !== null || ($current->tool() !== null && $current->tool() !== $choice);

        if ($current->declared !== $choice) {
            $spec = $this->packageManagerVersions->latest($choice, $this->projectRoot, $nodeVersion);

            if ($spec === null) {
                $ui->warning(sprintf(
                    "Couldn't look up %s's current version (is the network down?), so package.json is unchanged. Run `ship package-manager %s` after `ship up` to %s.",
                    $choice,
                    $choice,
                    $switching ? 'switch the project to it' : 'record it',
                ));

                return;
            }

            PackageManagerVersions::record($this->projectRoot, $spec);
            $ui->info("Recorded \"packageManager\": \"{$spec}\" in package.json.");
        }

        if ($switching) {
            $ui->note("Switching the project to {$choice} removes the old lockfile and node_modules and reinstalls, in the container: run `ship up`, then `ship package-manager {$choice}`.");
        }
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
     * Each group's label and the hint shown under its question.
     *
     * @return array<string, array{string, string}>
     */
    private function groups(): array
    {
        return [
            'database' => ['Database', 'The app\'s primary database; add more below'],
            'cache' => ['Cache', 'Sessions, cache and queues'],
            'runtime' => ['Application runtime', 'An Octane server, or None for PHP-FPM behind nginx'],
            'storage' => ['Object storage', 'An S3-compatible bucket for uploads'],
            'search' => ['Search engine', ''],
            'mail' => ['Mail capture', 'Catches every email sent in development'],
            'testing' => ['Browser testing', ''],
            'frontend' => ['Frontend tooling', ''],
            'broadcasting' => ['Broadcasting', 'WebSockets for real-time events'],
            'broker' => ['Message broker', ''],
        ];
    }
}
