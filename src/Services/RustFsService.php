<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * Alt driver for storage: an S3-compatible server written in Rust. Its web console isn't
 * published, since it currently returns "AccessDenied" instead of rendering (rustfs/rustfs#8013);
 * see SiloService for the option with a working console.
 *
 * There's no default-bucket provisioning: the app's bucket ("local", see environmentVariables())
 * has to be created once with any S3 client before first use.
 */
final class RustFsService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'rustfs';
    }

    public function label(): string
    {
        return 'RustFS (S3-compatible storage, Rust)';
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
                'image' => 'rustfs/rustfs:1.0.0',
                'environment' => [
                    // Must match environmentVariables() below, or "app" authenticates with
                    // credentials RustFS never provisioned. Overridable defaults; the secret key
                    // is required in production (see RequiredEnv).
                    'RUSTFS_ACCESS_KEY' => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
                    'RUSTFS_SECRET_KEY' => RequiredEnv::expr("{$prefix}AWS_SECRET_ACCESS_KEY", 'shipsecret', $environment),
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/data"],
                'healthcheck' => [
                    'test' => ['CMD', 'curl', '-f', 'http://127.0.0.1:9000/health'],
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
            // The same expressions composeFragment() provisions RustFS with.
            "{$prefix}AWS_ACCESS_KEY_ID" => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
            "{$prefix}AWS_SECRET_ACCESS_KEY" => "\${{$prefix}AWS_SECRET_ACCESS_KEY:-shipsecret}",
            "{$prefix}AWS_BUCKET" => 'local',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}
