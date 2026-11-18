<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Docker\ComposeCommand;
use Ship\Node\PackageManager;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `ship package-manager <npm|yarn|pnpm>[@version]`: makes the project use one package manager, the
 * one fix every package-manager problem points at (see PackageManager).
 *
 * Switching removes the other tools' lockfiles and the installed packages (node_modules, Yarn's
 * Plug'n'Play files), which were laid out by the old tool; nothing is removed without asking. It
 * then records the tool in package.json's packageManager and installs with it, creating its
 * lockfile from package.json. For Yarn and pnpm, Corepack resolves the version (`yarn@stable`,
 * `pnpm@latest`, or what's given, such as `pnpm@10`) and records it exactly. npm comes with the
 * project's Node version, so `npm` records that npm's version.
 *
 * Run in the app container, as `ship npm` is: node_modules is usually owned by the container's
 * user, so the host often couldn't remove it. Run with the tool the project already uses, it
 * removes nothing and only updates the recorded version (e.g. `ship package-manager pnpm@latest`).
 */
final class PackageManagerCommand extends Command
{
    /**
     * What a tool's Plug'n'Play/install state leaves next to node_modules, removed on a switch.
     */
    private const INSTALL_STATE = ['node_modules', '.pnp.cjs', '.pnp.loader.mjs', '.yarn/cache', '.yarn/unplugged', '.yarn/install-state.gz'];

    private const DEFAULT_VERSIONS = ['yarn' => 'stable', 'pnpm' => 'latest'];

    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
        private readonly string $appService = 'app',
    ) {
        parent::__construct('package-manager');
        $this->setDescription('Switch the project to npm, Yarn or pnpm (or update the recorded version)');
    }

    protected function configure(): void
    {
        $this->addArgument('tool', InputArgument::REQUIRED, 'npm, yarn or pnpm, optionally with a version: yarn@4, pnpm@latest');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Don\'t ask before removing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $argument */
        $argument = $input->getArgument('tool');
        [$tool, $version] = array_pad(explode('@', trim($argument), 2), 2, null);

        if (!in_array($tool, PackageManager::TOOLS, true)) {
            $output->writeln("<error>ship: \"{$argument}\" isn't a package manager ship supports: npm, yarn or pnpm.</error>");

            return Command::FAILURE;
        }

        if ($tool === 'npm' && $version !== null) {
            $output->writeln('<error>ship: npm comes with Node, so its version follows nodeVersion in ship.json. Run `ship package-manager npm`.</error>');

            return Command::FAILURE;
        }

        $current = PackageManager::inspect($this->projectRoot);

        if (!$current->hasPackageJson) {
            $output->writeln('<error>ship: there\'s no package.json in the project. Create one first, e.g. with `ship npm init`.</error>');

            return Command::FAILURE;
        }

        // Keep the version package.json already names for this tool (e.g. chosen by `ship init`),
        // without its checksum, which Corepack writes again.
        if ($version === null && $tool !== 'npm') {
            $version = $current->declared === $tool && $current->declaredSpec !== null
                ? explode('+', explode('@', $current->declaredSpec, 2)[1] ?? '', 2)[0]
                : '';
            $version = $version !== '' ? $version : self::DEFAULT_VERSIONS[$tool];
        }

        $remove = $this->filesToRemove($current, $tool);

        $output->writeln($current->tool() === $tool && $remove === []
            ? "Updating the project's {$tool} version:"
            : "Switching the project to {$tool}:");
        foreach ($remove as $path) {
            $output->writeln("  remove   {$path}");
        }
        $output->writeln(sprintf('  record   package.json packageManager: %s', $tool === 'npm' ? 'npm@<the npm Node comes with>' : "{$tool}@{$version}, as Corepack resolves it"));
        $output->writeln("  install  {$tool} install, creating " . PackageManager::LOCKFILES[$tool][0]);

        if ($remove !== [] && $input->getOption('yes') !== true) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>ship: removing files needs confirmation. Run it again with --yes.</error>');

                return Command::FAILURE;
            }

            if (!(new SymfonyStyle($input, $output))->confirm('Continue?', false)) {
                $output->writeln('Nothing changed.');

                return Command::SUCCESS;
            }
        }

        $exitCode = $this->runner->runInteractive([
            ...ComposeCommand::execPrefix($this->projectRoot, $this->appService),
            $this->appService,
            'sh',
            '-c',
            $this->script($tool, $version, $remove),
        ], $this->projectRoot);

        if ($exitCode !== 0) {
            $output->writeln("<error>ship: switching to {$tool} failed (exit code {$exitCode}). Is the app running? Start it with `ship up`, then run this again.</error>");

            return Command::FAILURE;
        }

        $after = PackageManager::inspect($this->projectRoot);
        $problem = $after->problem();
        if ($problem !== null) {
            $output->writeln("<error>ship: {$problem}</error>");

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>The project uses %s (%s). Commit package.json and %s%s.</info>',
            $tool,
            $after->declaredSpec !== null ? explode('+', $after->declaredSpec, 2)[0] : $tool,
            $after->lockfiles[$tool] ?? PackageManager::LOCKFILES[$tool][0],
            array_values(array_intersect($remove, $this->allLockfiles())) === []
                ? ''
                : ', and the removal of ' . implode(', ', array_intersect($remove, $this->allLockfiles())),
        ));

        return Command::SUCCESS;
    }

    /**
     * The other tools' lockfiles, and the installed packages when another tool may have laid them
     * out. Nothing when the project already uses $tool consistently.
     *
     * @return list<string>
     */
    private function filesToRemove(PackageManager $current, string $tool): array
    {
        if ($current->tool() === $tool) {
            return [];
        }

        $lockfiles = [];
        foreach ($current->lockfiles as $owner => $file) {
            if ($owner !== $tool) {
                $lockfiles[] = $file;
            }
        }

        $installed = array_values(array_filter(
            self::INSTALL_STATE,
            fn (string $path): bool => file_exists($this->projectRoot . '/' . $path),
        ));

        return [...$lockfiles, ...array_map(fn (string $path): string => is_dir($this->projectRoot . '/' . $path) ? $path . '/' : $path, $installed)];
    }

    /**
     * @param list<string> $remove
     */
    private function script(string $tool, ?string $version, array $remove): string
    {
        $steps = ['set -e'];

        if ($remove !== []) {
            $steps[] = 'rm -rf ' . implode(' ', array_map(static fn (string $path): string => escapeshellarg(rtrim($path, '/')), $remove));
        }

        if ($tool === 'npm') {
            // npm doesn't enforce packageManager; it records which npm the lockfile was made with.
            $steps[] = 'npm pkg set "packageManager=npm@$(npm --version)"';
            $steps[] = 'npm install';
        } else {
            // Resolves the version, records it (with its checksum) in packageManager, and installs.
            $steps[] = 'corepack use ' . escapeshellarg("{$tool}@{$version}");
        }

        return implode(' && ', $steps);
    }

    /**
     * @return list<string>
     */
    private function allLockfiles(): array
    {
        return array_merge(...array_values(PackageManager::LOCKFILES));
    }
}
