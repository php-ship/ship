<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * The production healthchecks of the services that run the app, so `docker compose up --wait`
 * waits until they really serve. They check that the server serves, not that the app works: that
 * deeper probe is release.json's `http[].health`, which a deployment tool requests.
 *
 * Development has none: the first boot runs `composer install`, which can outlast any budget.
 */
final class Healthchecks
{
    /**
     * php-fpm answers its ping (ship/php-fpm.conf) over FastCGI.
     *
     * @return array<string, mixed>
     */
    public static function phpFpm(): array
    {
        return self::serverTiming([
            'CMD-SHELL',
            'SCRIPT_NAME=/ship-fpm-ping SCRIPT_FILENAME=/ship-fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong',
        ]);
    }

    /**
     * nginx passes php-fpm's ping through to "app": nginx serves, and reaches PHP.
     *
     * @return array<string, mixed>
     */
    public static function nginx(): array
    {
        return self::serverTiming(['CMD-SHELL', 'wget -qO- http://127.0.0.1/ship-fpm-ping | grep -q pong']);
    }

    /**
     * A process's own health check (see ShipConfig::$processHealthchecks), run by the container's
     * shell. Every "$" is escaped so Compose's interpolation leaves the command's own variables
     * alone, as for the process's command.
     *
     * @return array<string, mixed>
     */
    public static function command(string $command): array
    {
        return self::serverTiming(['CMD-SHELL', str_replace('$', '$$', $command)]);
    }

    /**
     * $path on $port answers 2xx (a redirect is followed): the health path a ship.json process
     * declares (see ShipConfig::$processHttp). PHP's HTTP wrapper fails on 4xx and 5xx.
     *
     * @return array<string, mixed>
     */
    public static function httpPath(int $port, string $path): array
    {
        return self::serverTiming([
            'CMD',
            'php',
            '-r',
            sprintf("exit(@file_get_contents(%s, false, stream_context_create(['http' => ['timeout' => 5]])) === false ? 1 : 0);", var_export("http://127.0.0.1:{$port}{$path}", true)),
        ]);
    }

    /**
     * An HTTP server on $port answers at all. Any status counts: a 500 still means it is serving
     * (used for Octane and Reverb).
     *
     * @return array<string, mixed>
     */
    public static function httpServer(int $port): array
    {
        return self::serverTiming([
            'CMD',
            'php',
            '-r',
            "exit(@file_get_contents('http://127.0.0.1:{$port}/', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]])) === false ? 1 : 0);",
        ]);
    }

    /**
     * The container's main process has stayed up for 10 seconds: a worker without a port can't
     * answer anything, but one that crashes on boot and restarts in a loop never gets there. $$ is
     * Compose's escape for a literal $.
     *
     * @return array<string, mixed>
     */
    public static function stayedUp(): array
    {
        return [
            'test' => [
                'CMD-SHELL',
                'test "$$(awk -v up="$$(cut -d" " -f1 /proc/uptime)" \'{print int(up - $$22 / 100)}\' /proc/1/stat)" -ge 10',
            ],
            'interval' => '5s',
            'timeout' => '5s',
            'retries' => 3,
            'start_period' => '30s',
        ];
    }

    /**
     * Production containers run the framework's boot-time commands (`artisan optimize`) first.
     *
     * @param list<string> $test
     * @return array<string, mixed>
     */
    private static function serverTiming(array $test): array
    {
        return ['test' => $test, 'interval' => '10s', 'timeout' => '5s', 'retries' => 6, 'start_period' => '60s'];
    }
}
