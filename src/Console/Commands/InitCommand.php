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

            $answer = $this->select($io, $input, "Select a {$label}", $choices);

            if ($answer !== self::NONE) {
                $selected[$group] = $answer;
            }
        }

        $additionalServices = $this->promptForAdditionalInstances($io, $input);

        $phpVersion = $io->ask('PHP version', '8.4');
        $nodeVersion = $io->ask('Node.js version', '24');

        $config = new ShipConfig(
            phpVersion: (string) $phpVersion,
            services: $selected,
            nodeVersion: (string) $nodeVersion,
            additionalServices: $additionalServices,
        );
        $config->toFile($this->projectRoot . '/ship.json');

        $this->publishStubs($selected);
        $this->warnAboutViteDevServerConfigIfNeeded($io);
        $this->warnAboutMissingReverbPackageIfNeeded($io, $selected);

        $io->success('Wrote ship.json and published the ship/ directory. Run `ship up` to build and start the environment.');

        return Command::SUCCESS;
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
                    port: 5173,
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
    private function promptForAdditionalInstances(SymfonyStyle $io, InputInterface $input): array
    {
        $additional = [];
        $usedNames = [];

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
     */
    private function publishStubs(array $selected): void
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
        }

        if (($selected['storage'] ?? null) === 'garage') {
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
    }

    /**
     * A real security fix: the builder stage's `COPY . .` would bake .env, and any secrets in it, straight
     * into the image without this -- recoverable later via `docker history` even after a following step
     * deletes it, since layers are additive. Merged into any existing .dockerignore, not overwritten.
     */
    private function ensureDockerignoreExcludesEnv(): void
    {
        $path = $this->projectRoot . '/.dockerignore';
        $required = [
            '.env',
            '.env.*',
            '!.env.example',
            '.git',
            'node_modules',
            'vendor',
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
