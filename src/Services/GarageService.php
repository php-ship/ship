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
                ],
                // The image is FROM scratch with only the /garage binary in it.
                'command' => ['/garage', 'server', '--single-node', '--default-access-key', '--default-bucket'],
                'environment' => [
                    // Must match environmentVariables() below exactly, or "app"
                    // authenticates with credentials Garage never provisioned. "ship"/"shipsecret"
                    // are only the *defaults* -- same `${VAR:-default}` pattern every other
                    // credentialed service (MySqlService's DB_PASSWORD, ...) already uses, so a
                    // project's own .env/.env.production can override them instead of every Garage
                    // deployment everywhere sharing one publicly-known, hardcoded credential.
                    'GARAGE_DEFAULT_ACCESS_KEY' => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
                    'GARAGE_DEFAULT_SECRET_KEY' => "\${{$prefix}AWS_SECRET_ACCESS_KEY:-shipsecret}",
                    'GARAGE_DEFAULT_BUCKET' => "\${{$prefix}AWS_BUCKET:-local}",
                ],
                // Persisted in both environments -- see MySqlService's own comment for why.
                // $environment is otherwise unused here now -- required by ServiceDefinition regardless.
                'volumes' => ["ship-{$name}-data:/data", "ship-{$name}-meta:/meta"],
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

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            "{$prefix}AWS_ENDPOINT" => "http://{$name}:3900",
            "{$prefix}AWS_USE_PATH_STYLE_ENDPOINT" => 'true',
            "{$prefix}AWS_DEFAULT_REGION" => 'garage',
            // Same expressions as composeFragment()'s own GARAGE_DEFAULT_ACCESS_KEY/
            // GARAGE_DEFAULT_SECRET_KEY/GARAGE_DEFAULT_BUCKET, so both sides always resolve from
            // the same source at the same compose-parse time, never two independent guesses.
            "{$prefix}AWS_ACCESS_KEY_ID" => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
            "{$prefix}AWS_SECRET_ACCESS_KEY" => "\${{$prefix}AWS_SECRET_ACCESS_KEY:-shipsecret}",
            "{$prefix}AWS_BUCKET" => "\${{$prefix}AWS_BUCKET:-local}",
        ];
    }

    public function removes(): array
    {
        return [];
    }
}
