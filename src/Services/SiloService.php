<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;
use Ship\Docker\RequiredEnv;

/**
 * Alt driver for storage. Silo (pgsty/silo) is a community-maintained fork of the MinIO server
 * that keeps the web console and prebuilt images upstream's community edition dropped. Same
 * protocol and data format, same AGPLv3 license.
 *
 * There's no default-bucket provisioning: the app's bucket ("local", see environmentVariables())
 * has to be created once, via the console at http://localhost:${SILO_CONSOLE_PORT:-9001} or any
 * S3 client, before first use.
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
        $prefix = $this->envPrefix($instanceName);

        return [
            $name => [
                'image' => 'pgsty/silo:RELEASE.2026-09-16T00-00-00Z',
                'command' => ['server', '/data', '--console-address', ':9001'],
                'environment' => [
                    // Must match environmentVariables() below, or "app" authenticates with
                    // credentials Silo never provisioned. Overridable defaults; the password is
                    // required in production (see RequiredEnv), which matters here because the
                    // console can be published to the host.
                    'MINIO_ROOT_USER' => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
                    'MINIO_ROOT_PASSWORD' => RequiredEnv::expr("{$prefix}AWS_SECRET_ACCESS_KEY", 'shipsecret', $environment),
                ],
                // Only the console is host-published; "app" reaches the S3 API over the "ship"
                // network. The port's env var is instance-scoped so a named instance can't
                // collide with the default one.
                'ports' => [DevPortBinding::bind($this->consolePortMapping($instanceName), $environment)],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/data"],
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

        // AWS SDK-standard names, usable by any S3 client.
        return [
            "{$prefix}AWS_ENDPOINT" => "http://{$name}:9000",
            "{$prefix}AWS_USE_PATH_STYLE_ENDPOINT" => 'true',
            "{$prefix}AWS_DEFAULT_REGION" => 'us-east-1',
            // The same expressions composeFragment() provisions Silo with.
            "{$prefix}AWS_ACCESS_KEY_ID" => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
            "{$prefix}AWS_SECRET_ACCESS_KEY" => "\${{$prefix}AWS_SECRET_ACCESS_KEY:-shipsecret}",
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
