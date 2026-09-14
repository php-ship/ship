<?php

declare(strict_types=1);

namespace Ship\Console;

use Ship\Config\ShipConfig;
use Ship\Console\Commands\DbCommand;
use Ship\Console\Commands\DownCommand;
use Ship\Console\Commands\ExecCommand;
use Ship\Console\Commands\InitCommand;
use Ship\Console\Commands\LogsCommand;
use Ship\Console\Commands\ProxyCommand;
use Ship\Console\Commands\ShellCommand;
use Ship\Console\Commands\UpCommand;
use Ship\Contracts\FrameworkAdapter;
use Ship\Extensions\ExtensionLoader;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;

final class Application extends SymfonyApplication
{
    /** @var list<string> */
    private array $extensionClasses = [];

    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct('ship', '0.1.0-dev');

        $runner = new ProcessRunner();
        $registry = new ServiceRegistry(ServiceRegistry::defaults());

        // ship.json won't exist yet on a first-ever `ship init` run, so
        // this has to degrade gracefully rather than require the file.
        $this->extensionClasses = $this->readExtensionClassesIfConfigured();
        $warnings = (new ExtensionLoader())->load($this->extensionClasses, $registry);

        $this->registerCommand(new InitCommand($this->projectRoot, $registry));
        $this->registerCommand(new UpCommand($this->projectRoot, $runner));
        $this->registerCommand(new DownCommand($this->projectRoot, $runner));
        $this->registerCommand(new ExecCommand($this->projectRoot, $runner));
        $this->registerCommand(new ShellCommand($this->projectRoot, $runner));
        $this->registerCommand(new LogsCommand($this->projectRoot, $runner));
        $this->registerCommand(new DbCommand($this->projectRoot, $runner));

        // Package-manager commands are framework-agnostic, so they're
        // always available regardless of what FrameworkAdapter matches.
        // Node is installed unconditionally in the base image (see
        // NodeService's docblock), so `ship npm` always targets "app"
        // too — there's no separate node container to route to.
        $this->registerCommand(new ProxyCommand('composer', 'app', 'composer', $this->projectRoot, $runner));
        $this->registerCommand(new ProxyCommand('npm', 'app', 'npm', $this->projectRoot, $runner));

        foreach ($this->detectFrameworkAdapters() as $adapter) {
            foreach ($adapter->consoleCommands() as $commandName => $binary) {
                $this->registerCommand(new ProxyCommand($commandName, 'app', $binary, $this->projectRoot, $runner));
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
            ...(new ExtensionLoader())->loadFrameworkAdapters($this->extensionClasses),
        ];

        return array_values(array_filter(
            $candidates,
            fn (FrameworkAdapter $adapter): bool => $adapter->detect($this->projectRoot),
        ));
    }

    /**
     * @return list<string>
     */
    private function readExtensionClassesIfConfigured(): array
    {
        $path = $this->projectRoot . '/ship.json';

        if (!is_file($path)) {
            return [];
        }

        try {
            return ShipConfig::fromFile($path)->extensions;
        } catch (\Throwable) {
            // A malformed ship.json shouldn't block `ship init` from being
            // able to fix it — commands that actually need the config
            // (UpCommand etc.) will surface the real parse error themselves.
            return [];
        }
    }
}
