<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Alt driver for storage. Silo (pgsty/silo) is a community-maintained fork of the actual MinIO
 * server codebase -- upstream MinIO gutted its own community edition's web console and stopped
 * shipping community binaries, so this restores both on the same, unmodified MinIO protocol/data
 * format. AGPLv3, the same license MinIO itself always shipped under -- unchanged by forking it,
 * not a new consideration introduced by picking this over MinIO directly. This is the storage
 * option with an actually-working console (verified live: a real HTML/JS console page, not just a
 * 200 status) -- see RustFsService's own docblock for the other S3-compatible option considered
 * alongside this one, whose console currently does not work.
 *
 * Like Garage, has no built-in default-bucket provisioning -- MinIO (and so Silo) has never
 * supported auto-creating a bucket on first write; the app's own bucket ("local", see
 * environmentVariables()) has to be created once by hand, via the console at
 * http://localhost:${SILO_CONSOLE_PORT:-9001} or any S3 client, before first use.
 */
final class SiloService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'silo';
    }

    public function label(): string
    {
        return 'Silo (S3-compatible storage, MinIO-compatible, with a web console)';
    }

    public function group(): string
    {
        return 'storage';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);

        return [
            $name => [
                'image' => 'pgsty/silo:RELEASE.2026-09-16T00-00-00Z',
                'command' => ['server', '/data', '--console-address', ':9001'],
                'environment' => [
                    // Must match environmentVariables() below exactly, or "app"
                    // authenticates with credentials Silo never provisioned.
                    'MINIO_ROOT_USER' => 'ship',
                    'MINIO_ROOT_PASSWORD' => 'shipsecret',
                ],
                // Only the console is host-published -- the S3 API itself is only ever reached by
                // "app" over the internal "ship" network (see environmentVariables()'s
                // AWS_ENDPOINT), same as SeaweedFS/Garage's own S3 ports, neither of which
                // publishes one at all. The console port's own env var is instance-scoped so a
                // second named instance (storage supports additionalServices) doesn't silently
                // collide with the default one on the same host port.
                'ports' => [$this->consolePortMapping($instanceName)],
                'volumes' => $environment->isDevelopment()
                    ? ["ship-{$name}-data:/data"]
                    : [],
                'healthcheck' => [
                    'test' => ['CMD', 'curl', '-f', 'http://127.0.0.1:9000/minio/health/live'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 10,
                    'start_period' => '10s',
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        // Generic AWS SDK-standard names, not framework-specific, so any
        // S3 client (Laravel's Storage facade, aws-sdk-php directly,
        // Flysystem, ...) can consume them the same way.
        return [
            "{$prefix}AWS_ENDPOINT" => "http://{$name}:9000",
            "{$prefix}AWS_USE_PATH_STYLE_ENDPOINT" => 'true',
            "{$prefix}AWS_DEFAULT_REGION" => 'us-east-1',
            "{$prefix}AWS_ACCESS_KEY_ID" => 'ship',
            "{$prefix}AWS_SECRET_ACCESS_KEY" => 'shipsecret',
            "{$prefix}AWS_BUCKET" => 'local',
        ];
    }

    public function removes(): array
    {
        return [];
    }

    private function consolePortMapping(?string $instanceName): string
    {
        $envVar = $instanceName === null ? 'SILO_CONSOLE_PORT' : strtoupper($instanceName) . '_SILO_CONSOLE_PORT';

        return "\${{$envVar}:-9001}:9001";
    }
}
