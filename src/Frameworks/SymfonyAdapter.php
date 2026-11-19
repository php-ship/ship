<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\SuggestsProcesses;
use Ship\Support\ComposerPackages;
use Symfony\Component\Yaml\Yaml;

/**
 * Symfony's `bin/console`/`cache:clear` equivalents of LaravelAdapter's `artisan`/`optimize`.
 */
final class SymfonyAdapter implements FrameworkAdapter, DescribesRelease, SuggestsProcesses
{
    public function detect(string $projectRoot): bool
    {
        return is_file($projectRoot . '/bin/console')
            && is_file($projectRoot . '/composer.json');
    }

    public function consoleCommands(): array
    {
        return [
            'console' => 'php bin/console',
        ];
    }

    /**
     * cache:clear recompiles the container and warms the cache. No --env/--no-debug flags: it
     * relies on APP_ENV/APP_DEBUG being set as real env vars at boot.
     *
     * @return list<string>
     */
    public function releaseCommands(): array
    {
        return ['php bin/console cache:clear'];
    }

    public function frameworkName(): string
    {
        return 'symfony';
    }

    /**
     * Symfony has no built-in health route.
     */
    public function healthPath(string $projectRoot): ?string
    {
        return null;
    }

    /**
     * Symfony has no standard setting for the site's own URL.
     */
    public function siteUrlSetting(): ?string
    {
        return null;
    }

    /**
     * Symfony reads its settings from the .env files in the image as well as the environment, so
     * nothing is required from .env.production specifically.
     */
    public function requiredEnv(string $projectRoot): array
    {
        return [];
    }

    /**
     * A Messenger worker for the transports config/packages/messenger.yaml defines (not the failure
     * transport, nor a sync:// one, which has nothing to consume), and one for Scheduler's
     * schedules (`#[AsSchedule]` in src/, consumed as `scheduler_<name>` transports). Both stop
     * after an hour, as Symfony advises for long-running workers; the container restarts them.
     */
    public function suggestedProcesses(string $projectRoot, array $services): array
    {
        $processes = [];

        $transports = ComposerPackages::has($projectRoot, 'symfony/messenger') ? self::messengerTransports($projectRoot) : [];
        if ($transports !== []) {
            $processes['messenger'] = [
                'label' => 'Messenger worker: consumes ' . implode(', ', $transports),
                'command' => 'php bin/console messenger:consume ' . implode(' ', $transports) . ' --time-limit=3600',
            ];
        }

        $schedules = ComposerPackages::has($projectRoot, 'symfony/scheduler') ? self::schedules($projectRoot) : [];
        if ($schedules !== []) {
            $processes['scheduler'] = [
                'label' => 'Scheduler: runs the ' . implode(', ', $schedules) . ' schedule' . (count($schedules) > 1 ? 's' : ''),
                'command' => 'php bin/console messenger:consume ' . implode(' ', array_map(static fn (string $name): string => "scheduler_{$name}", $schedules)) . ' --time-limit=3600',
            ];
        }

        return $processes;
    }

    /**
     * @return list<string>
     */
    private static function messengerTransports(string $projectRoot): array
    {
        try {
            $config = Yaml::parseFile($projectRoot . '/config/packages/messenger.yaml', Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable) {
            return [];
        }

        $messenger = is_array($config) && is_array($config['framework']['messenger'] ?? null) ? $config['framework']['messenger'] : [];
        $failure = $messenger['failure_transport'] ?? null;
        $transports = [];

        foreach (is_array($messenger['transports'] ?? null) ? $messenger['transports'] : [] as $name => $transport) {
            $dsn = is_array($transport) ? ($transport['dsn'] ?? '') : $transport;
            if (is_string($name) && $name !== $failure && !(is_string($dsn) && str_starts_with($dsn, 'sync://'))) {
                $transports[] = $name;
            }
        }

        return $transports;
    }

    /**
     * @return list<string>
     */
    private static function schedules(string $projectRoot): array
    {
        if (!is_dir($projectRoot . '/src')) {
            return [];
        }

        $schedules = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectRoot . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/#\[AsSchedule(?:\(\s*(?:name\s*:\s*)?[\'"]([^\'"]+)[\'"])?/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $name) {
                $schedules[] = $name === '' ? 'default' : $name;
            }
        }

        return array_values(array_unique($schedules));
    }
}
