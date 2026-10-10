<?php

declare(strict_types=1);

namespace Ship\Sync;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mutagen (https://mutagen.io/) syncs the project root into the "app" container instead of using
 * `ship up`'s bind mount. Opt-in through the SHIP_MUTAGEN environment variable rather than
 * ship.json, since it only helps on Windows/macOS, where Docker Desktop's bind mounts are slow.
 * Development only.
 *
 * Driven directly with `mutagen sync create`/`terminate`, without the separate mutagen-compose
 * plugin.
 */
final class MutagenSync
{
    private const APP_SYNC_PATH = '/var/www/html';
    private const SYNC_TIMEOUT_SECONDS = 120;
    private const POLL_INTERVAL_SECONDS = 1;
    // Creating a session scans the whole local tree, which can outrun runQuiet()'s 30s default
    // on a large project. Waiting for the sync to finish is the separate SYNC_TIMEOUT_SECONDS.
    private const CREATE_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $projectRoot,
        private readonly string $appServiceName = 'app',
    ) {
    }

    public static function isEnabled(): bool
    {
        $value = strtolower(trim((string) getenv('SHIP_MUTAGEN')));

        return $value === '1' || $value === 'true';
    }

    /**
     * An empty `mutagen version` result means the binary isn't on PATH or isn't runnable.
     */
    public function isBinaryAvailable(): bool
    {
        return $this->runner->runQuiet(['mutagen', 'version']) !== '';
    }

    /**
     * Creates the sync session if this project doesn't have one, then blocks until it reaches
     * "Watching" on both sides. The container's APP_SYNC_PATH is empty until the first sync
     * finishes, so returning earlier would report success for an app serving an empty directory.
     */
    public function start(OutputInterface $output): int
    {
        if (!$this->isBinaryAvailable()) {
            $output->writeln(
                '<error>ship: SHIP_MUTAGEN is set but the `mutagen` binary was not found on PATH. '
                    . 'Install it from https://mutagen.io/documentation/introduction/installation, or '
                    . 'unset SHIP_MUTAGEN to use the default bind mount instead.</error>',
            );

            return Command::FAILURE;
        }

        if ($this->isWatching()) {
            // Re-checked when the session already existed: a previous `ship up` may have been
            // interrupted before installing. A no-op once vendor/autoload.php exists.
            if ($this->installComposerDependencies() !== Command::SUCCESS) {
                $output->writeln('<error>ship: composer install failed inside the "app" container -- see the output above.</error>');

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $containerName = trim($this->runner->runQuiet(
            [...ComposeCommand::baseArgs($this->projectRoot), 'ps', $this->appServiceName, '--format', '{{.Name}}'],
            $this->projectRoot,
        ));

        if ($containerName === '') {
            $output->writeln(sprintf(
                '<error>ship: could not resolve the "%s" container to sync into.</error>',
                $this->appServiceName,
            ));

            return Command::FAILURE;
        }

        $output->writeln('<comment>ship: starting Mutagen file sync...</comment>');

        // vendor/ and node_modules/ are excluded: both can contain platform-specific binaries
        // that would be wrong inside the Linux container. vendor/ is installed in the container
        // (see installComposerDependencies()); node_modules/ needs a `ship npm install`.
        $create = $this->runner->runQuietWithResult([
            'mutagen', 'sync', 'create',
            '--name', $this->sessionName(),
            '--label', $this->labelSelector(),
            '--mode', 'two-way-resolved',
            '--ignore-vcs',
            '--ignore', 'vendor',
            '--ignore', 'node_modules',
            $this->projectRoot,
            "docker://{$containerName}" . self::APP_SYNC_PATH,
        ], timeoutSeconds: self::CREATE_TIMEOUT_SECONDS);

        // Fail now with Mutagen's own error (daemon not running, stale session, bad endpoint)
        // rather than poll for a sync that will never start.
        if ($create['exitCode'] !== 0) {
            $output->writeln(sprintf(
                '<error>ship: `mutagen sync create` failed: %s</error>',
                $create['errorOutput'] !== '' ? $create['errorOutput'] : ($create['output'] !== '' ? $create['output'] : 'unknown error'),
            ));

            return Command::FAILURE;
        }

        for ($elapsed = 0; $elapsed < self::SYNC_TIMEOUT_SECONDS; $elapsed += self::POLL_INTERVAL_SECONDS) {
            if ($this->isWatching()) {
                $output->writeln('<info>ship: Mutagen sync is up and watching for changes.</info>');

                if ($this->installComposerDependencies() !== Command::SUCCESS) {
                    $output->writeln('<error>ship: composer install failed inside the "app" container -- see the output above.</error>');

                    return Command::FAILURE;
                }

                return Command::SUCCESS;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        $output->writeln(sprintf(
            '<error>ship: Mutagen sync did not finish its initial sync within %ds. Check '
                . '`mutagen sync list --label-selector=%s`.</error>',
            self::SYNC_TIMEOUT_SECONDS,
            $this->labelSelector(),
        ));

        return Command::FAILURE;
    }

    /**
     * vendor/ is excluded from the sync, and the dev entrypoint's install-if-missing step runs at
     * boot, before composer.json has synced. So the install runs here, once the sync has
     * finished, with the same guard (composer.json present, vendor/autoload.php missing).
     */
    private function installComposerDependencies(): int
    {
        return $this->runner->runInteractive([
            ...ComposeCommand::baseArgs($this->projectRoot),
            'exec', '-T', $this->appServiceName,
            'sh', '-c', 'if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then composer install --no-interaction; fi',
        ], $this->projectRoot);
    }

    /**
     * Safe to call whether or not a session exists: `mutagen sync terminate` exits 0 on an empty
     * label-selector match. Skipped when the binary isn't available.
     */
    public function stop(): void
    {
        if (!$this->isBinaryAvailable()) {
            return;
        }

        $this->runner->runQuiet(['mutagen', 'sync', 'terminate', '--label-selector', $this->labelSelector()]);
    }

    /**
     * True once the session is fully synced and idle: "Watching" with both endpoints connected.
     * Looked up by label, since a session name isn't unique (see label()).
     *
     * @phpstan-impure genuinely non-deterministic across calls with the same arguments (there are
     *                 none): it shells out to `mutagen sync list`, whose result depends on the
     *                 sync session's real-world progress, not on anything PHPStan can see.
     */
    private function isWatching(): bool
    {
        $raw = $this->runner->runQuiet([
            'mutagen', 'sync', 'list',
            '--label-selector', $this->labelSelector(),
            '--template', '{{range .}}{{.Status}}|{{.Alpha.Connected}}|{{.Beta.Connected}}{{"\n"}}{{end}}',
        ]);

        if ($raw === '') {
            return false;
        }

        foreach (explode("\n", $raw) as $line) {
            $fields = explode('|', $line);

            if ($fields[0] === 'Watching' && ($fields[1] ?? '') === 'true' && ($fields[2] ?? '') === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * A short, stable hash of the project's real path, since Mutagen label values don't accept
     * path characters. Unique per project, so lookups and teardown never touch another project's
     * sessions.
     */
    private function label(): string
    {
        $realProjectRoot = realpath($this->projectRoot);

        return substr(hash('sha256', $realProjectRoot !== false ? $realProjectRoot : $this->projectRoot), 0, 16);
    }

    private function labelSelector(): string
    {
        return "ship-project={$this->label()}";
    }

    private function sessionName(): string
    {
        return "ship-{$this->label()}";
    }
}
