<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * Runs SeaweedFS's server subcommand: master, volume and filer/S3 gateway in one process.
 *
 * The S3 gateway has no authentication unless given an identity file via `-s3.config`, and
 * there's no env-var alternative. The entrypoint is therefore overridden with a shell that writes
 * that file from AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY at container boot, then execs the
 * server.
 */
final class SeaweedFsService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'seaweedfs';
    }

    public function label(): string
    {
        return 'SeaweedFS (S3-compatible storage)';
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
                'image' => 'chrislusf/seaweedfs:4.46',
                // Replaces the image's ENTRYPOINT (the `weed` binary) so the identity file can be
                // generated at boot.
                'entrypoint' => ['/bin/sh', '-c'],
                // $$ rather than $: Compose interpolates a bare $VAR itself, against the host's
                // environment, and would blank both credentials.
                //
                // Each credential is JSON-escaped with sed (the image has no jq), so a `"` or
                // `\` in a secret doesn't produce an invalid identity file.
                'command' => [
                    'mkdir -p /etc/seaweedfs'
                        . ' && ACCESS_KEY_ESC=$$(printf \'%s\' "$$AWS_ACCESS_KEY_ID" | sed \'s/\\\\/\\\\\\\\/g; s/"/\\\\"/g\')'
                        . ' && SECRET_KEY_ESC=$$(printf \'%s\' "$$AWS_SECRET_ACCESS_KEY" | sed \'s/\\\\/\\\\\\\\/g; s/"/\\\\"/g\')'
                        . ' && printf \'{"identities":[{"name":"ship","credentials":'
                        . '[{"accessKey":"%s","secretKey":"%s"}],"actions":["Admin","Read","Write","List",'
                        . '"Tagging"]}]}\' "$$ACCESS_KEY_ESC" "$$SECRET_KEY_ESC" > /etc/seaweedfs/s3_identity.json'
                        . ' && exec weed server -s3 -s3.port=8333 -s3.config=/etc/seaweedfs/s3_identity.json -dir=/data',
                ],
                'environment' => [
                    // Must match environmentVariables() below. The secret key is required in
                    // production (see RequiredEnv); the access key id keeps a plain default.
                    'AWS_ACCESS_KEY_ID' => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
                    'AWS_SECRET_ACCESS_KEY' => RequiredEnv::expr("{$prefix}AWS_SECRET_ACCESS_KEY", 'shipsecret', $environment),
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/data"],
                'healthcheck' => [
                    // 127.0.0.1, not "localhost": the image resolves ::1 first, which nothing
                    // listens on.
                    'test' => ['CMD', 'wget', '-q', '-O', '-', 'http://127.0.0.1:9333/cluster/status'],
                    'interval' => '10s',
                    'timeout' => '5s',
                    'retries' => 5,
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        // AWS SDK-standard names, usable by any S3 client. The credentials are Compose
        // expressions rather than literals, so .env/.env.production values reach the app
        // (`environment:` beats `env_file:`).
        return [
            "{$prefix}AWS_ENDPOINT" => "http://{$name}:8333",
            "{$prefix}AWS_USE_PATH_STYLE_ENDPOINT" => 'true',
            "{$prefix}AWS_DEFAULT_REGION" => 'us-east-1',
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
