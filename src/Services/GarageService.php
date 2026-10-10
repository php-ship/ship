<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * Alt driver for storage. Needs a config file before it starts, published from
 * stubs/docker/garage/garage.toml by InitCommand. --single-node creates the cluster layout;
 * --default-access-key/--default-bucket provision credentials and a bucket on boot. The RPC
 * secret and admin token come from env vars, not that file (see composeFragment()).
 */
final class GarageService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'garage';
    }

    public function label(): string
    {
        return 'Garage (S3-compatible storage, lightweight alt.)';
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
                'build' => [
                    'context' => './ship/garage',
                    'dockerfile' => 'Dockerfile',
                    // garage.toml's rpc_public_addr must match this instance's compose service
                    // name. The Dockerfile's "config" stage substitutes it at build time, since
                    // the final FROM-scratch image has no shell to do it at boot.
                    'args' => ['GARAGE_RPC_PUBLIC_ADDR' => "{$name}:3901"],
                ],
                // The image is FROM scratch with only the /garage binary in it.
                'command' => ['/garage', 'server', '--single-node', '--default-access-key', '--default-bucket'],
                'environment' => [
                    // Must match environmentVariables() below, or "app" authenticates with
                    // credentials Garage never provisioned. Overridable defaults; the secret key
                    // is required in production (see RequiredEnv).
                    //
                    // Longer than the other storage services' defaults because this image
                    // enforces minimum lengths: 8 characters for the key id, 16 for the secret.
                    'GARAGE_DEFAULT_ACCESS_KEY' => "\${{$prefix}AWS_ACCESS_KEY_ID:-shipaccesskey}",
                    'GARAGE_DEFAULT_SECRET_KEY' => RequiredEnv::expr("{$prefix}AWS_SECRET_ACCESS_KEY", 'shipsecretplaceholder', $environment),
                    'GARAGE_DEFAULT_BUCKET' => "\${{$prefix}AWS_BUCKET:-local}",
                    // Per-project secrets, so garage.toml ships neither. Garage reads both from
                    // these env vars, which override the config file.
                    'GARAGE_RPC_SECRET' => RequiredEnv::expr(
                        "{$prefix}GARAGE_RPC_SECRET",
                        '0000000000000000000000000000000000000000000000000000000000000000',
                        $environment,
                    ),
                    'GARAGE_ADMIN_TOKEN' => RequiredEnv::expr("{$prefix}GARAGE_ADMIN_TOKEN", 'shipsecret', $environment),
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/data", "ship-{$name}-meta:/meta"],
                // No shell or curl in a FROM-scratch image; `status` checks the node over its
                // local RPC.
                'healthcheck' => [
                    'test' => ['CMD', '/garage', 'status'],
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

        return [
            "{$prefix}AWS_ENDPOINT" => "http://{$name}:3900",
            "{$prefix}AWS_USE_PATH_STYLE_ENDPOINT" => 'true',
            "{$prefix}AWS_DEFAULT_REGION" => 'garage',
            // The same expressions composeFragment() provisions Garage with.
            "{$prefix}AWS_ACCESS_KEY_ID" => "\${{$prefix}AWS_ACCESS_KEY_ID:-shipaccesskey}",
            "{$prefix}AWS_SECRET_ACCESS_KEY" => "\${{$prefix}AWS_SECRET_ACCESS_KEY:-shipsecretplaceholder}",
            "{$prefix}AWS_BUCKET" => "\${{$prefix}AWS_BUCKET:-local}",
        ];
    }

    public function removes(): array
    {
        return [];
    }
}
