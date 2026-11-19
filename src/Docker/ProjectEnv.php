<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;
use Ship\Contracts\ConfigAwareService;
use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ShipEnvironment;
use Ship\Frameworks\Frameworks;
use Ship\Services\ServiceRegistry;

/**
 * The settings the project's services use, kept visible in its env files (`ship init` adds what's
 * missing; `ship config:test` reports it):
 *
 * - .env and .env.example: every setting a service passes to the app, with the development value
 *   ship uses. A fixed value (DB_HOST=postgres) is what the containers get whatever .env says; a
 *   value that follows .env (`${DB_PASSWORD:-secret}`) is written as its default; one built from
 *   others (SQL Server's DB_URL) as a reference (`"...${DB_PASSWORD}..."`), which the frameworks'
 *   dotenv readers expand, so it can't go stale.
 * - .env.production: the settings a release can't run without (the services' credentials, the
 *   framework's own), empty: a release still refuses until they're filled in.
 *
 * Only missing keys are added, at the end, under a comment saying why; nothing is changed or
 * removed. A key that's only commented out counts as missing.
 */
final class ProjectEnv
{
    /**
     * The development settings: key => the value to write.
     *
     * @return array<string, string>
     */
    public static function development(ShipConfig $config, ServiceRegistry $registry): array
    {
        $values = [];

        foreach (self::environmentVariables($config, $registry) as $key => $expression) {
            $values[$key] = self::render($key, $expression);
        }

        return $values;
    }

    /**
     * The settings ship fixes for the containers whatever the env files say (DB_HOST=postgres):
     * key => value.
     *
     * @return array<string, string>
     */
    public static function fixed(ShipConfig $config, ServiceRegistry $registry): array
    {
        return array_filter(
            self::environmentVariables($config, $registry),
            static fn (string $expression): bool => !str_contains($expression, '${'),
        );
    }

    /**
     * The settings a release refuses to run without: what the production compose file requires
     * (see RequiredEnv) and what the framework does (see DescribesRelease).
     *
     * @param list<FrameworkAdapter> $extensionAdapters
     * @return list<string>
     */
    public static function productionRequired(ShipConfig $config, ServiceRegistry $registry, string $projectRoot, array $extensionAdapters = []): array
    {
        $required = RequiredEnv::requiredIn((new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Production, projectRoot: $projectRoot));

        foreach (Frameworks::detected($projectRoot, $extensionAdapters) as $adapter) {
            if ($adapter instanceof DescribesRelease) {
                $required = [...$required, ...$adapter->requiredEnv($projectRoot)];
                break;
            }
        }

        return array_values(array_unique($required));
    }

    /**
     * The keys of $keys that $path doesn't set (a commented-out key doesn't count).
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function missingFrom(string $path, array $keys): array
    {
        $present = EnvFile::parse($path);

        return array_values(array_filter($keys, static fn (string $key): bool => !array_key_exists($key, $present)));
    }

    /**
     * Where $path gives a fixed setting another value: key => [the file's, ship's].
     *
     * @param array<string, string> $fixed
     * @return array<string, array{string, string}>
     */
    public static function conflicts(string $path, array $fixed): array
    {
        $present = EnvFile::parse($path);
        $conflicts = [];

        foreach ($fixed as $key => $value) {
            if (array_key_exists($key, $present) && $present[$key] !== $value) {
                $conflicts[$key] = [$present[$key], $value];
            }
        }

        return $conflicts;
    }

    /**
     * Appends $entries to $path (created when missing) under a "# $comment" line, keeping a blank
     * line before it.
     *
     * @param array<string, string> $entries
     */
    public static function append(string $path, string $comment, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $block = '# ' . $comment . "\n";
        foreach ($entries as $key => $value) {
            $block .= $key . '=' . self::quote($value) . "\n";
        }

        $separator = match (true) {
            $existing === '' => '',
            str_ends_with($existing, "\n\n") => '',
            str_ends_with($existing, "\n") => "\n",
            default => "\n\n",
        };

        file_put_contents($path, $existing . $separator . $block);
    }

    /**
     * Every setting the selected services and named instances pass to the app, as ship writes
     * them into the compose file.
     *
     * @return array<string, string>
     */
    private static function environmentVariables(ShipConfig $config, ServiceRegistry $registry): array
    {
        $variables = [];
        $instances = [
            ...array_map(static fn (string $key): array => [$key, null], array_values($config->services)),
            ...array_map(static fn (array $instance): array => [$instance['service'], $instance['name']], $config->additionalServices),
        ];

        foreach ($instances as [$key, $instanceName]) {
            if (!$registry->has($key)) {
                continue;
            }

            $service = $registry->get($key);
            if ($service instanceof ConfigAwareService) {
                $service = $service->withConfig($config);
            }

            // A service that runs only in development still sets its settings there (Mailpit's
            // MAIL_HOST): .env is development's file.
            $variables += $service->environmentVariables($instanceName);
        }

        return $variables;
    }

    /**
     * A compose expression as an env file value: its own default for a key that follows the file
     * (`${KEY:-default}`), references without defaults for one built from others.
     */
    private static function render(string $key, string $expression): string
    {
        if (preg_match('/^\$\{' . preg_quote($key, '/') . ':-([^}]*)\}$/', $expression, $match) === 1) {
            return $match[1];
        }

        return (string) preg_replace('/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::[-?][^}]*)?\}/', '${$1}', $expression);
    }

    /**
     * Double-quoted when it holds a reference, a space or a "#", which a bare value would lose.
     */
    private static function quote(string $value): string
    {
        return preg_match('/[\s#"\'$\\\\]/', $value) === 1
            ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"'
            : $value;
    }
}
