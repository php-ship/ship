<?php

declare(strict_types=1);

namespace Ship\Console;

use Ship\Config\ShipConfig;
use Ship\Console\Commands\BuildCommand;
use Ship\Console\Commands\ConfigTestCommand;
use Ship\Console\Commands\DbCommand;
use Ship\Console\Commands\DownCommand;
use Ship\Console\Commands\ExecCommand;
use Ship\Console\Commands\InitCommand;
use Ship\Console\Commands\LogsCommand;
use Ship\Console\Commands\ProxyCommand;
use Ship\Console\Commands\ReleaseCommand;
use Ship\Console\Commands\ShellCommand;
use Ship\Console\Commands\UpCommand;
use Ship\Contracts\FrameworkAdapter;
use Ship\Extensions\ExtensionLoader;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
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
        // ShipVersion::current() (Composer's own InstalledVersions) is the real installed
        // version, so `ship --version` reflects whatever's actually installed. Still falls back
        // to the literal "0.1.0-dev" when InstalledVersions can't answer at all (running straight
        // from a git checkout with no Composer metadata, same case ShipVersion::current()'s own
        // callers already degrade gracefully for).
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

        // $registry/$this->extensionFrameworkAdapters are passed into every command below instead
        // of letting each one build and populate its own fresh registry independently -- that
        // would reload the exact same extension classes all over again on every real `ship`
        // invocation, not just print the duplicate *warning* the fwrite() below already avoids.
        // See UpCommand's own constructor docblock.
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

        // Package-manager commands are framework-agnostic, so they're
        // always available regardless of what FrameworkAdapter matches.
        // Node is installed unconditionally in the base image (see
        // NodeService's docblock), so `ship npm` always targets the app
        // service too — there's no separate node container to route to.
        $this->registerCommand(new ProxyCommand('composer', $appServiceName, 'composer', $this->projectRoot, $runner));
        $this->registerCommand(new ProxyCommand('npm', $appServiceName, 'npm', $this->projectRoot, $runner));

        foreach ($this->detectFrameworkAdapters() as $adapter) {
            foreach ($adapter->consoleCommands() as $commandName => $binary) {
                $this->registerCommand(new ProxyCommand($commandName, $appServiceName, $binary, $this->projectRoot, $runner));
            }
        }

        // Surfaced on the next command run rather than thrown from the
        // constructor — a broken extension shouldn't prevent `ship` from
        // running at all, just warn every time until it's fixed.
        foreach ($warnings as $warning) {
            fwrite(STDERR, "ship: warning: {$warning}\n");
        }
    }

    /**
     * `Application::add()` was removed in symfony/console 8.0 in favor of `addCommand()` (added in 7.4) --
     * exactly why composer.json requires `symfony/console: ^7.4 || ^8.0` specifically here, not `^7.0`.
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
        // Registering more than one adapter class here is fine — detect()
        // is what decides whether it actually contributes commands for
        // this project, so an unmatched adapter is simply a no-op.
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
            // A malformed ship.json shouldn't block `ship init` from being
            // able to fix it — commands that actually need the config
            // (UpCommand etc.) will surface the real parse error themselves.
            return null;
        }
    }
}
