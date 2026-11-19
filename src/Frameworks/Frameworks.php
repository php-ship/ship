<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\RequiresPhpVersion;
use Ship\Contracts\SuggestsProcesses;

/**
 * The framework adapters ship knows: its own, then any ship.json extensions add. The one list
 * every command detects frameworks from (the proxy commands, the boot-time commands of a
 * production image, release.json, `ship init`'s PHP version), so a new built-in adapter is added
 * here and nowhere else.
 */
final class Frameworks
{
    /**
     * @return list<FrameworkAdapter>
     */
    public static function builtIn(): array
    {
        return [new LaravelAdapter(), new SymfonyAdapter(), new TempestAdapter()];
    }

    /**
     * The adapters that apply to the project at $projectRoot, built-in ones first.
     *
     * @param list<FrameworkAdapter> $extensionAdapters
     * @return list<FrameworkAdapter>
     */
    public static function detected(string $projectRoot, array $extensionAdapters = []): array
    {
        return array_values(array_filter(
            [...self::builtIn(), ...$extensionAdapters],
            static fn (FrameworkAdapter $adapter): bool => $adapter->detect($projectRoot),
        ));
    }

    /**
     * The newest of the minimum PHP versions the project's frameworks require (see
     * RequiresPhpVersion), and which framework requires it; null when none does.
     *
     * @param list<FrameworkAdapter> $extensionAdapters
     * @return ?array{version: string, framework: string}
     */
    public static function minimumPhp(string $projectRoot, array $extensionAdapters = []): ?array
    {
        $minimum = null;

        foreach (self::detected($projectRoot, $extensionAdapters) as $adapter) {
            if (!$adapter instanceof RequiresPhpVersion) {
                continue;
            }

            $version = $adapter->minimumPhpVersion($projectRoot);
            if ($minimum === null || version_compare($version, $minimum['version'], '>')) {
                $minimum = [
                    'version' => $version,
                    'framework' => $adapter instanceof DescribesRelease ? ucfirst($adapter->frameworkName()) : 'This project\'s framework',
                ];
            }
        }

        return $minimum;
    }

    /**
     * The processes the project's frameworks suggest (see SuggestsProcesses), the first
     * framework's winning a name two of them suggest.
     *
     * @param array<string, string> $services
     * @param list<FrameworkAdapter> $extensionAdapters
     * @return array<string, array{label: string, command: string, healthcheck?: string, http?: array{port: int, role: string, health: ?string, urlSetting: ?string}}>
     */
    public static function suggestedProcesses(string $projectRoot, array $services, array $extensionAdapters = []): array
    {
        $suggested = [];

        foreach (self::detected($projectRoot, $extensionAdapters) as $adapter) {
            if ($adapter instanceof SuggestsProcesses) {
                $suggested += $adapter->suggestedProcesses($projectRoot, $services);
            }
        }

        return $suggested;
    }

    /**
     * Why $phpVersion is too old for the project's frameworks, or null.
     *
     * @param list<FrameworkAdapter> $extensionAdapters
     */
    public static function phpVersionProblem(string $projectRoot, string $phpVersion, array $extensionAdapters = []): ?string
    {
        $minimum = self::minimumPhp($projectRoot, $extensionAdapters);

        return $minimum !== null && version_compare(trim($phpVersion), $minimum['version'], '<')
            ? "{$minimum['framework']} needs PHP {$minimum['version']} or newer, so the image's `composer install` would fail on PHP {$phpVersion}."
            : null;
    }
}
