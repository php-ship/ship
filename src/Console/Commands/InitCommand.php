<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
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

    // Groups whose built-in ServiceDefinitions actually give each instance its own env var
    // prefix and compose service name (see SupportsNamedInstances) -- a second runtime, testing
    // driver, frontend tool, or broadcasting server has nothing to name, only one of any of
    // those ever makes sense per project, so this list deliberately excludes them.
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

            // Keyed by service key (not label), so both prompt backends
            // return the same shape and no label-matching lookup is
            // needed afterwards.
            $choices = [self::NONE => 'None', ...array_combine(
                array_map(static fn ($s) => $s->key(), $options),
                array_map(static fn ($s) => $s->label(), $options),
            )];

            // Defaults to this group's existing selection (if any, and if it's still a valid
            // choice) so re-running `ship init` on a project that already has a ship.json doesn't
            // silently reset every group to "None" the moment the user just accepts each prompt's
            // own default -- confirmed live: without this, a second `ship init` run dropped a
            // project's already-selected database/cache/etc. entirely unless every prompt was
            // re-answered by hand.
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
            publishPorts: $existing->publishPorts ?? true,
            deployCommands: $existing->deployCommands ?? [],
            processes: $existing->processes ?? [],
            hostUser: $existing->hostUser ?? false,
            name: $existing?->name,
        );
        $config->toFile($this->projectRoot . '/ship.json');

        $this->publishStubs($selected, $additionalServices, $config->serviceNames['app'] ?? 'app');
        $this->warnAboutViteDevServerConfigIfNeeded($io);
        $this->warnAboutMissingReverbPackageIfNeeded($io, $selected);

        $io->success('Wrote ship.json and published the ship/ directory. Run `ship up` to build and start the environment.');

        return Command::SUCCESS;
    }

    /**
     * A real bug found via an independent audit: re-running `ship init` built a fresh ShipConfig
     * from only the four fields this command's own prompts ever touch (php, node, services,
     * additionalServices), silently dropping every hand-edited field that isn't prompted for at
     * all -- extensions, serviceNames, externalNetwork, phpExtensions, publishPorts,
     * deployCommands, processes, hostUser, name. The README calls re-running "always safe" and
     * `ship up` itself tells users to do it after a stub-version mismatch; losing `publishPorts:
     * false` alone silently re-exposes ports a project turned off deliberately. Null (not an
     * exception) when there's nothing to preserve yet -- a first-ever `ship init` -- or the
     * existing file isn't even valid JSON, since a broken ship.json is exactly what re-running
     * `ship init` might be trying to fix in the first place.
     *
     * Uses ShipConfig::tryFromFile(), not fromFile() -- a real bug found via an independent
     * re-audit of this very fix: fromFile() validates and throws on the first invalid field it
     * finds (e.g. one bad serviceNames value), which this method's own try/catch then treated as
     * "nothing to preserve," reintroducing the exact data-loss bug being fixed here, just behind a
     * new trigger. tryFromFile() preserves a field that's merely the wrong format as-is instead of
     * throwing or discarding it -- see its own docblock for why that's the right tradeoff
     * specifically here.
     */
    private function readExistingConfig(): ?ShipConfig
    {
        return ShipConfig::tryFromFile($this->projectRoot . '/ship.json');
    }

    /**
     * ship publishes Vite's dev server port but can't safely auto-edit an existing vite.config.js for just
     * host/hmr -- patching arbitrary JS isn't worth the fragility. Only warns when the project actually
     * uses Vite and the config doesn't already look handled, so re-running init won't nag every time.
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
     * The reverb service unconditionally runs `php artisan reverb:start` -- a fresh Laravel app doesn't
     * have that command until `laravel/reverb` is actually required, so without this warning `ship up`
     * would just crash-loop the reverb container with a confusing "no commands defined" error.
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
     * A project needing two genuinely different data stores at once -- a Postgres primary and a
     * MySQL replica of a legacy system's data, a second Redis for a purpose the default one
     * shouldn't share -- can't express that through the single-select loop above, since ship.json's
     * `services` holds exactly one selection per group. This is the opt-in way to add more:
     * each answer here becomes one ship.json `additionalServices` entry, distinguished by the name
     * given (also the env var prefix and compose service suffix -- see SupportsNamedInstances).
     *
     * @return list<array{group: string, service: string, name: string}>
     */
    /**
     * $existing (ship.json's current additionalServices, if any) is kept as-is, not re-prompted
     * for -- re-running `ship init` has no interactive way to edit or remove one of these, so
     * silently starting from an empty list every time discarded every previously-added instance
     * the moment the project's ship.json was regenerated, confirmed live. Removing one is still
     * possible by hand-editing ship.json directly, same as serviceNames/phpExtensions/etc.
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

                // Becomes both a Compose service name suffix ("pgsql-{$name}") and an environment
                // variable prefix (strtoupper($name) . '_', see SupportsNamedInstances) -- anything
                // outside this charset breaks one or the other. Confirmed live: a space here makes
                // `docker compose config` reject the whole file with "services additional
                // properties '...' not allowed", an error that never points back to this prompt.
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
     * A plain substring check, not a JS parse -- permissive on purpose, since a false "yes" is harmless.
     */
    private function viteConfigLooksAlreadyHandled(string $path): bool
    {
        return str_contains((string) file_get_contents($path), 'hmr');
    }

    /**
     * Uses Laravel Prompts' select() when actually usable here, falling back to plain ChoiceQuestion.
     *
     * Not a hard Composer dependency: laravel/prompts conflicts with laravel/framework 10.17-10.24, and so
     * requiring it here would break any project in that version window. Detecting it at runtime keeps a
     * free ride in the common case: Laravel 11+ already requires it, falling back silently otherwise.
     *
     * @param array<string, string> $choicesByKey option key => label
     * @param ?string $default must be an actual key in $choicesByKey, or omitted to default to the
     *        first one -- Laravel Prompts errors on a default that isn't one of its own options,
     *        which self::NONE never is for a choice list that has no "None" (the additional-instance
     *        prompts below, unlike the main per-group loop, which always passes self::NONE here).
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

        // Numeric-indexed list, not $choicesByKey's own string keys --
        // ChoiceQuestion renders an associative array's keys directly,
        // so this keeps the prompt showing "[1] Postgres" instead of
        // internal service keys like "[pgsql]" or "[__none__]".
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
     * Laravel itself wires up Prompts' Windows-outside-WSL fallback, via its own provider, never runs when
     * Prompts gets called standalone, exactly how ship calls it. Now this checks those same conditions,
     * directly (PHP_OS_FAMILY, isInteractive()) instead of trusting Prompts' own fallback to kick in.
     */
    private function canUseLaravelPromptsInteractiveUi(InputInterface $input): bool
    {
        return function_exists('Laravel\Prompts\select')
            && $input->isInteractive()
            && PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * Copies the Dockerfile, php.ini overlays, and (conditionally) the nginx/garage config into the actual
     * project's ship folder; ensures .dockerignore excludes .env (see ensureDockerignoreExcludesEnv()).
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

        // nginx is only needed when no Octane runtime is selected — see
        // ComposeFileBuilder::baseServices() for the matching condition.
        // Only default.conf now (not a whole directory mirror) -- nginx
        // no longer has its own Dockerfile; it builds through
        // ship/Dockerfile's dev-nginx/prod-nginx targets instead, see
        // that file for why.
        if (! isset($selected['runtime'])) {
            $filesystem->mkdir($target . '/nginx');
            $filesystem->copy(
                $packageRoot . '/stubs/docker/nginx/default.conf',
                $target . '/nginx/default.conf',
                overwriteNewerFiles: true,
            );

            // A real bug found via an independent audit: the stub hardcodes "app:9000" --
            // renaming the app service via ship.json's serviceNames (e.g. to share a Docker
            // network with another ship project) left nginx still trying to reach a DNS name
            // nothing in the stack answers to anymore, 502ing every request. Only rewritten when
            // it actually differs, so the overwhelming majority of projects that never touch
            // serviceNames get the exact same file as before.
            if ($appServiceName !== 'app') {
                $confPath = $target . '/nginx/default.conf';
                file_put_contents($confPath, str_replace('app:9000', "{$appServiceName}:9000", (string) file_get_contents($confPath)));
            }
        }

        // A real bug found via an independent audit: this only ever checked the default storage
        // pick, so Garage selected *only* as a named additionalServices instance (ship.json's
        // storage group is one of GROUPS_SUPPORTING_ADDITIONAL_INSTANCES) never got its stub
        // files published at all -- that instance's own `build: {context: ./ship/garage}` had
        // nothing to build from.
        $garageSelected = ($selected['storage'] ?? null) === 'garage'
            || in_array('garage', array_column($additionalServices, 'service'), strict: true);

        if ($garageSelected) {
            $filesystem->mirror(
                $packageRoot . '/stubs/docker/garage',
                $target . '/garage',
                options: ['override' => true],
            );
        }

        // Read back by UpCommand to warn when a project upgrades `ship` without re-running
        // `init` -- the published stub files stay whatever version wrote them, silently, unless
        // something compares. Omitted (not written as "unknown") when the version can't be
        // determined at all, so that case can't ever falsely claim a mismatch later either.
        $version = ShipVersion::current();
        if ($version !== null) {
            file_put_contents($target . '/.ship-version', $version . "\n");
        }

        $this->ensureDockerignoreExcludesEnv();
        $this->ensureGitignoreExcludesDist();
    }

    /**
     * A real security fix: the builder stage's `COPY . .` would bake .env, and any secrets in it, straight
     * into the image without this -- recoverable later via `docker history` even after a following step
     * deletes it, since layers are additive. Merged into any existing .dockerignore, not overwritten.
     *
     * `**`-prefixed, not bare `.env`/`.env.*` -- confirmed live, not assumed: a bare pattern only
     * matches at the build context *root*, not recursively, so a nested file (most importantly
     * `dist/ship/<tag>/.env`, `ship release`'s own copy of `.env.production`) was NOT excluded by
     * the un-prefixed form, and landed readable inside the very next image built in that same
     * project -- an actual production secret leak, not a theoretical one. `/dist/ship` -- not the
     * bare `/dist` a real re-audit of this exact fix flagged -- is excluded for the same reason:
     * that's specifically `ship release`'s own generated output (see ReleaseCommand's own
     * `$releaseDir`), images and all, carried forward release after release, never a build input.
     * A bare `/dist` instead silently dropped a project's *own* `dist/` -- a separate build tool's
     * real output the image might legitimately need to `COPY . .` in -- from the build context
     * entirely, with no error, confirmed in a real build.
     */
    private function ensureDockerignoreExcludesEnv(): void
    {
        $path = $this->projectRoot . '/.dockerignore';
        $required = [
            '**/.env',
            '**/.env.*',
            '!**/.env.example',
            '.git',
            'node_modules',
            'vendor',
            '/dist/ship',
        ];

        $fileLines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $existing = $fileLines === false ? [] : $fileLines;
        $missing = array_values(array_diff($required, $existing));

        if ($missing === []) {
            return;
        }

        $header = $existing === [] ? "# Added by `ship init` -- keeps secrets and build noise out of the\n# Docker build context.\n" : "\n# Added by `ship init`:\n";

        file_put_contents($path, $header . implode("\n", $missing) . "\n", FILE_APPEND);
    }

    /**
     * `ship release --tag` writes a release's images (docker save tars, often hundreds of MB) and
     * `.env` (copied from `.env.production`) under here -- generated output, and a real secrets
     * leak risk, neither of which belongs in the project's own git history. Merged into any
     * existing .gitignore, not overwritten, and a no-op when the project already ignores it under
     * some wider pattern.
     */
    private function ensureGitignoreExcludesDist(): void
    {
        $path = $this->projectRoot . '/.gitignore';
        $required = '/dist/ship/';

        $fileLines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $existing = $fileLines === false ? [] : $fileLines;

        if (in_array($required, $existing, true)) {
            return;
        }

        $header = $existing === [] ? "# Added by `ship init` -- `ship release`'s own generated output.\n" : "\n# Added by `ship init`:\n";

        file_put_contents($path, $header . $required . "\n", FILE_APPEND);
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
