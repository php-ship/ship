<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Services\ServiceRegistry;
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

    public function __construct(
        private readonly string          $projectRoot,
        private readonly ServiceRegistry $registry,
    )
    {
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
                array_map(static fn($s) => $s->key(), $options),
                array_map(static fn($s) => $s->label(), $options),
            )];

            $answer = $this->select($io, $input, "Select a {$label}", $choices);

            if ($answer !== self::NONE) {
                $selected[$group] = $answer;
            }
        }

        $phpVersion = $io->ask('PHP version', '8.4');

        $config = new ShipConfig(
            phpVersion: (string) $phpVersion,
            services: $selected,
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
     * @param array<string, string> $choicesByKey service key => label,
     *        always includes self::NONE => 'None'
     */
    private function select(
        SymfonyStyle $io,
        InputInterface $input,
        string $label,
        array $choicesByKey,
    ): string {
        if ($this->canUseLaravelPromptsInteractiveUi($input)) {
            /** @var string */
            return \Laravel\Prompts\select(label: $label, options: $choicesByKey, default: self::NONE);
        }

        // Numeric-indexed list, not $choicesByKey's own string keys --
        // ChoiceQuestion renders an associative array's keys directly,
        // so this keeps the prompt showing "[1] Postgres" instead of
        // internal service keys like "[pgsql]" or "[__none__]".
        $labels = array_values($choicesByKey);
        $keys = array_keys($choicesByKey);

        $question = new ChoiceQuestion($label, $labels, 0);
        $question->setErrorMessage('%s is not a valid choice.');

        /** @var string $answerLabel */
        $answerLabel = $io->askQuestion($question);
        $index = array_search($answerLabel, $labels, strict: true);

        return $index === false ? self::NONE : $keys[$index];
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

        $existing = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) ?: [] : [];
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
