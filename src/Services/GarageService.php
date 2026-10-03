<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * Alt driver for storage. Needs a config file present before it starts, unlike SeaweedFS -- published
 * from stubs/docker/garage/garage.toml by InitCommand. --single-node auto-creates the cluster layout
 * every node needs; --default-access-key/--default-bucket provision credentials on boot. The RPC
 * secret and admin token are deliberately *not* in that file -- see composeFragment()'s own
 * GARAGE_RPC_SECRET/GARAGE_ADMIN_TOKEN comment.
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
                    // Must match environmentVariables() below exactly, or "app" authenticates
                    // with credentials Garage never provisioned. These are only the *defaults* --
                    // same `${VAR:-default}` pattern every other credentialed service
                    // (MySqlService's DB_PASSWORD, ...) already uses, so a project's own
                    // .env/.env.production can override them instead of every Garage deployment
                    // everywhere sharing one publicly-known, hardcoded credential. The secret key
                    // specifically is required (not just overridable) in production -- see
                    // RequiredEnv's own docblock -- the access key id is access-key-ID-shaped, not
                    // secret-shaped, same reasoning DB_USERNAME is never required either.
                    //
                    // Longer than every other storage service's own "ship"/"shipsecret" defaults --
                    // two real bugs found live, unrelated to the independent audit: this specific
                    // image's own `--default-access-key` validates minimum lengths and refuses to
                    // start at all otherwise, not just warning ("Key identifiers should be at
                    // least 8 characters long", then, once that was fixed, "Secret keys should be
                    // at least 16 characters long").
                    'GARAGE_DEFAULT_ACCESS_KEY' => "\${{$prefix}AWS_ACCESS_KEY_ID:-shipaccesskey}",
                    'GARAGE_DEFAULT_SECRET_KEY' => RequiredEnv::expr("{$prefix}AWS_SECRET_ACCESS_KEY", 'shipsecretplaceholder', $environment),
                    'GARAGE_DEFAULT_BUCKET' => "\${{$prefix}AWS_BUCKET:-local}",
                    // garage.toml ships neither value at all -- a real bug found via an
                    // independent audit, confirmed live: the stub hardcoded an all-zero
                    // rpc_secret shared by every project that ever selected Garage, and the admin
                    // API (bucket/key management, reachable by anything else on the "ship"
                    // network) had no token at all. Garage's own CLI reads both of these as env
                    // vars directly (`garage --help`: GARAGE_RPC_SECRET/GARAGE_ADMIN_TOKEN,
                    // explicitly documented to override config.toml), so no wrapper script or
                    // generated file is needed the way SeaweedFS's identity config required.
                    'GARAGE_RPC_SECRET' => RequiredEnv::expr(
                        "{$prefix}GARAGE_RPC_SECRET",
                        '0000000000000000000000000000000000000000000000000000000000000000',
                        $environment,
                    ),
                    'GARAGE_ADMIN_TOKEN' => RequiredEnv::expr("{$prefix}GARAGE_ADMIN_TOKEN", 'shipsecret', $environment),
                ],
                // Persisted in both environments -- see MySqlService's own comment for why.
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
