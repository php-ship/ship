<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * Reads `docker compose ps --all --format json` against the services a compose file declares,
 * for `ship doctor`. Pure, so it is testable without Docker.
 */
final class StackHealth
{
    /**
     * Compose prints one JSON object per line; some versions print a single JSON array instead.
     *
     * @return array<string, array<string, mixed>> service name => its ps record
     */
    public static function parseRecords(string $output): array
    {
        $records = [];
        $trimmed = trim($output);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, associative: true);
        $rows = is_array($decoded) && array_is_list($decoded)
            ? $decoded
            : array_map(
                static fn (string $line): mixed => json_decode($line, associative: true),
                array_values(array_filter(explode("\n", $trimmed), static fn (string $line): bool => trim($line) !== '')),
            );

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['Service'] ?? null)) {
                $records[$row['Service']] = $row;
            }
        }

        return $records;
    }

    /**
     * @param array<string, array<string, mixed>> $services the compose file's `services`
     * @param array<string, array<string, mixed>> $records from parseRecords()
     * @return array{lines: list<string>, problems: list<string>}
     */
    public static function diagnose(array $services, array $records): array
    {
        $lines = [];
        $problems = [];

        foreach ($services as $name => $service) {
            $record = $records[$name] ?? null;
            $image = is_string($service['image'] ?? null) ? $service['image'] : 'built from ' . self::dockerfile($service);
            $state = is_string($record['State'] ?? null) ? $record['State'] : 'missing';
            $health = is_string($record['Health'] ?? null) ? $record['Health'] : '';
            $lines[] = "{$name}: {$image}; {$state}" . ($health !== '' ? "; health {$health}" : '');

            if ($record === null) {
                $problems[] = "{$name} has no container";
            } elseif (in_array($state, ['exited', 'dead', 'restarting'], true) || $health === 'unhealthy') {
                $exitCode = $record['ExitCode'] ?? '?';
                $problems[] = "{$name} is {$state} (health: " . ($health !== '' ? $health : 'none') . ", exit code: {$exitCode})";
            } elseif ($state !== 'running' || $health === 'starting') {
                $problems[] = "{$name} is {$state} (health: " . ($health !== '' ? $health : 'none') . ')';
            }
        }

        return ['lines' => $lines, 'problems' => $problems];
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function dockerfile(array $service): string
    {
        $build = $service['build'] ?? null;

        if (is_array($build) && is_string($build['dockerfile'] ?? null)) {
            return $build['dockerfile'];
        }

        return 'a Dockerfile';
    }
}
