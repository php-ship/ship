<?php

declare(strict_types=1);

namespace Ship\Console;

use Ship\Config\ShipConfig;
use Ship\Console\Commands\BuildCommand;
use Ship\Console\Commands\ConfigTestCommand;
use Ship\Console\Commands\DbCommand;
use Ship\Console\Commands\DoctorCommand;
use Ship\Console\Commands\DownCommand;
use Ship\Console\Commands\ExecCommand;
use Ship\Console\Commands\InitCommand;
use Ship\Console\Commands\LogsCommand;
use Ship\Console\Commands\PackageManagerCommand;
use Ship\Console\Commands\ProxyCommand;
use Ship\Console\Commands\ReleaseCommand;
use Ship\Console\Commands\ShellCommand;
use Ship\Console\Commands\UpCommand;
use Ship\Contracts\FrameworkAdapter;
use Ship\Extensions\ExtensionLoader;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Node\PackageManager;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;

final class Application extends SymfonyApplication
{
    /** @var list<FrameworkAdapter> */
    private array $extensionFrameworkAdapters = [];

    public function __construct(private readonly string $projectRoot)
    {
        // Falls back to "0.1.0-dev" when Composer's InstalledVersions can't answer (a plain git
        // checkout).
        parent::__construct('ship', ShipVersion::current() ?? '0.1.0-dev');

        $runner = new ProcessRunner();
        $registry = new ServiceRegistry(ServiceRegistry::defaults());

        // ship.json won't exist yet on a first-ever `ship init` run, so
        // this has to degrade gracefully rather than require the file.
        $config = $this->readConfigIfPresent();
        $extensionClasses = $config === null ? [] : $config->extensions;
        $appServiceName = $config === null ? 'app' : ($config->serviceNames['app'] ?? 'app');
        $loaded = (new ExtensionLoader())->load($extensionClasses, $registry);
        $warnings = $loaded['warnings'];
        $this->extensionFrameworkAdapters = $loaded['frameworkAdapters'];

        // One registry, shared by every command, so extension classes are only loaded once.
        $this->registerCommand(new InitCommand($this->projectRoot, $registry));
        $this->registerCommand(new UpCommand($this->projectRoot, $runner, $registry));
        $this->registerCommand(new BuildCommand($this->projectRoot, $runner, $registry, $this->extensionFrameworkAdapters));
        $this->registerCommand(new ReleaseCommand($this->projectRoot, $runner, $registry, $this->extensionFrameworkAdapters));
        $this->registerCommand(new DownCommand($this->projectRoot, $runner));
        $this->registerCommand(new ExecCommand($this->projectRoot, $runner));
        $this->registerCommand(new ShellCommand($this->projectRoot, $runner));
        $this->registerCommand(new LogsCommand($this->projectRoot, $runner));
        $this->registerCommand(new DbCommand($this->projectRoot, $runner, $registry));
        $this->registerCommand(new ConfigTestCommand($this->projectRoot, $registry));
        $this->registerCommand(new DoctorCommand($this->projectRoot, $runner));

        // Package-manager commands are always available. Node is installed in the app image, with
        // Yarn and pnpm through Corepack, so `ship npm`/`yarn`/`pnpm` target the app service too.
        // Each refuses to run in a project that uses another of them (see PackageManager).
        $this->registerCommand(new ProxyCommand('composer', $appServiceName, 'composer', $this->projectRoot, $runner));
        foreach (PackageManager::TOOLS as $tool) {
            $this->registerCommand(new ProxyCommand($tool, $appServiceName, $tool, $this->projectRoot, $runner, packageManager: $tool));
        }
        $this->registerCommand(new PackageManagerCommand($this->projectRoot, $runner, $appServiceName));

        foreach ($this->detectFrameworkAdapters() as $adapter) {
            foreach ($adapter->consoleCommands() as $commandName => $binary) {
                $this->registerCommand(new ProxyCommand($commandName, $appServiceName, $binary, $this->projectRoot, $runner));
            }
        }

        // A broken extension only warns; it shouldn't stop `ship` from running.
        foreach ($warnings as $warning) {
            fwrite(STDERR, "ship: warning: {$warning}\n");
        }
    }

    /**
     * `add()` was removed in symfony/console 8.0 in favor of `addCommand()` (added in 7.4), hence
     * the `^7.4 || ^8.0` constraint in composer.json.
     */
    private function registerCommand(Command $command): void
    {
        $this->addCommand($command);
    }

    /**
     * @return list<FrameworkAdapter>
     */
    private function detectFrameworkAdapters(): array
    {
        // detect() decides whether an adapter contributes commands for this project.
        $candidates = [
            new LaravelAdapter(),
            new SymfonyAdapter(),
            ...$this->extensionFrameworkAdapters,
        ];

        return array_values(array_filter(
            $candidates,
            fn (FrameworkAdapter $adapter): bool => $adapter->detect($this->projectRoot),
        ));
    }

    private function readConfigIfPresent(): ?ShipConfig
    {
        $path = $this->projectRoot . '/ship.json';

        if (!is_file($path)) {
            return null;
        }

        try {
            return ShipConfig::fromFile($path);
        } catch (\Throwable) {
            // A malformed ship.json mustn't block `ship init`; commands that need the config
            // report the parse error themselves.
            return null;
        }
    }
}
