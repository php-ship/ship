<?php

declare(strict_types=1);

namespace Ship\Sync;

use Ship\Docker\ComposeCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mutagen (https://mutagen.io/) syncs the project root into the "app" container's own filesystem
 * in the background instead of `ship up`'s default bind mount -- opt-in via the SHIP_MUTAGEN
 * environment variable (a personal, per-machine choice, not a project-wide ship.json setting: it
 * only ever helps on Windows/macOS, where Docker Desktop's bind-mount translation layer makes
 * filesystem-heavy work slow, and is pure overhead on Linux, where bind mounts already talk to
 * the native filesystem directly -- see docs/roadmap.md). Development only: production bakes the
 * source into the image at build time (see "builder"/"prod" stages), so there's no bind mount --
 * or anything to sync -- to begin with.
 *
 * Orchestrated directly by ship itself (`mutagen sync create`/`terminate`), not the separate
 * mutagen-compose plugin some tutorials assume: that's a second binary beyond core Mutagen, and
 * ship already has everywhere it needs to hook in (UpCommand/DownCommand) without it.
 */
final class MutagenSync
{
    private const APP_SYNC_PATH = '/var/www/html';
    private const SYNC_TIMEOUT_SECONDS = 120;
    private const POLL_INTERVAL_SECONDS = 1;

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
     * `mutagen version` rather than a version check on some file -- the simplest, most direct way
     * to answer "is the binary on PATH and actually runnable," matching how the rest of this
     * codebase already treats an empty runQuiet() result as "the thing isn't there" (see
     * UpCommand::runningServices()) rather than inspecting a process exit code ProcessRunner
     * doesn't expose.
     */
    public function isBinaryAvailable(): bool
    {
        return $this->runner->runQuiet(['mutagen', 'version']) !== '';
    }

    /**
     * Creates the sync session if one for this project isn't already running, then blocks until
     * it actually reaches a steady "Watching" state on both sides -- not just until the create
     * command returns. Mutagen's Docker endpoint syncs directly into the container's own
     * filesystem via an agent it injects at session-creation time, so the container's
     * self::APP_SYNC_PATH starts out empty (see ComposeFileBuilder's Mutagen-mode volume swap) and
     * stays that way until this initial sync finishes -- returning early would let `ship up`
     * report success while the app is still serving out of an empty directory.
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
            // Re-checked even when the sync already existed from a previous `ship up` -- cheap
            // (a no-op the instant vendor/autoload.php exists, see installComposerDependencies()'s
            // own docblock) and covers a first attempt that created the session successfully but
            // was interrupted before installing, leaving a project stuck re-running `ship up` with
            // no other way to retry just this part.
            $this->installComposerDependencies();

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

        // vendor/ and node_modules/ are excluded, not just for the sheer file count -- both can
        // contain platform-specific compiled binaries (a handful of Composer packages, and any
        // npm package with a native postinstall step: esbuild, sharp, sass, swc-based tooling).
        // Syncing a Windows/macOS-installed copy into the Linux container would hand it binaries
        // built for the wrong platform. The container bootstraps its own vendor/ already (the dev
        // entrypoint's existing composer-install-if-missing fallback); node_modules/ needs the
        // same `ship npm install` a fresh bind-mount project would too -- no regression either way,
        // since bind-mount mode never auto-installs it now either.
        $this->runner->runQuiet([
            'mutagen', 'sync', 'create',
            '--name', $this->sessionName(),
            '--label', $this->labelSelector(),
            '--mode', 'two-way-resolved',
            '--ignore-vcs',
            '--ignore', 'vendor',
            '--ignore', 'node_modules',
            $this->projectRoot,
            "docker://{$containerName}" . self::APP_SYNC_PATH,
        ]);

        for ($elapsed = 0; $elapsed < self::SYNC_TIMEOUT_SECONDS; $elapsed += self::POLL_INTERVAL_SECONDS) {
            if ($this->isWatching()) {
                $output->writeln('<info>ship: Mutagen sync is up and watching for changes.</info>');
                $this->installComposerDependencies();

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
     * vendor/ is deliberately excluded from the sync itself (see start()'s own docblock on why),
     * which means nothing else ever populates it in this mode -- the dev entrypoint's own
     * composer-install-if-missing fallback runs at container *boot*, before this sync session even
     * exists yet, so composer.json isn't there for it to find either; it always skips. Found for
     * real, not hypothesized: a live CI run's `artisan migrate` right after a successful `ship up`
     * failed outright on a missing vendor/autoload.php. Same guard as that entrypoint fallback
     * (composer.json present, vendor/autoload.php missing) so a second `ship up` -- vendor/ already
     * there, persisted in the named volume same as everything else written inside the container --
     * costs nothing beyond one quick `docker compose exec`.
     */
    private function installComposerDependencies(): void
    {
        $this->runner->runInteractive([
            ...ComposeCommand::baseArgs($this->projectRoot),
            'exec', '-T', $this->appServiceName,
            'sh', '-c', 'if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then composer install --no-interaction; fi',
        ], $this->projectRoot);
    }

    /**
     * Safe to call unconditionally, whether or not a session for this project actually exists --
     * matching mutagen sync terminate's own behavior of exiting 0 on an empty label-selector match
     * rather than erroring, verified directly rather than assumed. Skips the terminate call
     * entirely when the binary isn't available at all: nothing this ship instance itself could
     * have started needs tearing down in that case.
     */
    public function stop(): void
    {
        if (!$this->isBinaryAvailable()) {
            return;
        }

        $this->runner->runQuiet(['mutagen', 'sync', 'terminate', '--label-selector', $this->labelSelector()]);
    }

    /**
     * True only once the session is fully synced and idle -- "Watching" (Mutagen's own steady
     * state, distinct from "Scanning"/"Staging"/"Reconciling" mid-sync) with both endpoints
     * actually connected. --label-selector, not --name: a name is just a display label here (two
     * sessions can share one, verified directly -- `mutagen sync create` doesn't reject a
     * duplicate), where a label is the only thing this checks by that's actually guaranteed
     * unique per project (see labelSelector()'s own docblock).
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
     * A short, stable hash of the project's real path -- not the path itself, which Mutagen's
     * label values (verified directly, not documented in --help) don't accept the characters of
     * ("/", ":", spaces on Windows). Stable across `ship up` runs for the *same* project, distinct
     * across different ones on the same machine, which --label-selector-based lookups and
     * teardown both depend on to never touch another project's sessions.
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
