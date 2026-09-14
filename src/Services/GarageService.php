<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Alt driver for storage. Needs a config file present before it starts, unlike SeaweedFS -- published
 * from stubs/docker/garage/garage.toml by InitCommand. --single-node auto-creates the cluster layout
 * every node needs; --default-access-key/--default-bucket provision credentials on boot.
 */
final class GarageService implements ServiceDefinition
{
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

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'garage' => [
                'build' => [
                    'context' => './ship/garage',
                    'dockerfile' => 'Dockerfile',
                ],
                // The image is FROM scratch with only the /garage binary in it.
                'command' => ['/garage', 'server', '--single-node', '--default-access-key', '--default-bucket'],
                'environment' => [
                    // Must match environmentVariables() below exactly, or "app"
                    // authenticates with credentials Garage never provisioned.
                    'GARAGE_DEFAULT_ACCESS_KEY' => 'ship',
                    'GARAGE_DEFAULT_SECRET_KEY' => 'shipsecret',
                    'GARAGE_DEFAULT_BUCKET' => 'local',
                ],
                'volumes' => $environment->isDevelopment()
                    ? ['ship-garage-data:/data', 'ship-garage-meta:/meta']
                    : [],
                // No shell or curl in a FROM-scratch image -- `status` is the
                // only way to check the node is up, over its own local RPC.
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

    public function environmentVariables(): array
    {
        return [
            'AWS_ENDPOINT' => 'http://garage:3900',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
            'AWS_DEFAULT_REGION' => 'garage',
            // Must match composeFragment()'s GARAGE_DEFAULT_ACCESS_KEY/
            // GARAGE_DEFAULT_SECRET_KEY/GARAGE_DEFAULT_BUCKET above.
            'AWS_ACCESS_KEY_ID' => 'ship',
            'AWS_SECRET_ACCESS_KEY' => 'shipsecret',
            'AWS_BUCKET' => 'local',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}
