<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;
use Ship\Contracts\ConfigAwareService;
use Ship\Contracts\ExposesHttp;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\ServiceRegistry;

/**
 * The services that receive HTTP traffic, with their container ports. One source of truth for
 * ComposeFileBuilder (which attaches them to an external network) and release.json's `http`.
 *
 * The site is "webserver" (nginx, port 80) when there is one, otherwise "app" itself, which an
 * Octane runtime serves on port 8000. Services implementing ExposesHttp add their own entries, and
 * ship.json processes with an `http` block theirs (see ShipConfig::$processHttp).
 */
final class HttpEntryPoints
{
    public const SITE_PORT_NGINX = 80;
    public const SITE_PORT_OCTANE = 8000;

    /**
     * @param array<string, array<string, mixed>> $services the generated compose services, with
     *        serviceNames renames already applied
     * The site's `health` and `urlSetting` are null here: only the framework knows them (see
     * DescribesRelease).
     *
     * @return list<array{service: string, port: int, role: string, health: ?string, urlSetting: ?string}>
     */
    public static function resolve(
        array $services,
        ShipConfig $config,
        ServiceRegistry $registry,
        ShipEnvironment $environment,
    ): array {
        $appServiceName = $config->serviceNames['app'] ?? 'app';
        $webserverServiceName = $config->serviceNames['webserver'] ?? 'webserver';

        $entries = [isset($services[$webserverServiceName])
            ? ['service' => $webserverServiceName, 'port' => self::SITE_PORT_NGINX, 'role' => 'site', 'health' => null, 'urlSetting' => null]
            : ['service' => $appServiceName, 'port' => self::SITE_PORT_OCTANE, 'role' => 'site', 'health' => null, 'urlSetting' => null]];

        foreach ($config->services as $key) {
            $service = $registry->get($key);

            if ($service instanceof ConfigAwareService) {
                $service = $service->withConfig($config);
            }

            if (!$service instanceof ExposesHttp || $service->composeFragment($environment) === []) {
                continue;
            }

            foreach ($service->httpEntryPoints() as $defaultName => $entry) {
                $name = $config->serviceNames[$defaultName] ?? $defaultName;

                if (isset($services[$name])) {
                    $entries[] = [
                        'service' => $name,
                        'port' => $entry['port'],
                        'role' => $entry['role'],
                        'health' => $entry['health'] ?? null,
                        'urlSetting' => $entry['urlSetting'] ?? null,
                    ];
                }
            }
        }

        // ship.json processes that declare they receive HTTP (production only, like processes).
        foreach ($config->processHttp as $name => $entry) {
            if (isset($services[$name])) {
                $entries[] = ['service' => $name, ...$entry];
            }
        }

        return $entries;
    }
}
