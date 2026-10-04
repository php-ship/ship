<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * Runs SeaweedFS's server subcommand -- master, volume, and filer/S3-gateway in one dev process.
 *
 * The base image's S3 gateway has no authentication at all unless handed an identity config file
 * via `-s3.config` -- an unsigned, credential-less request against a plain `weed server -s3`
 * returns 200 with a real bucket listing otherwise. There's no env-var-driven auth option, only a
 * config *file*, which the real secret (known only once `.env.production` exists, long after
 * `ship init` published anything) can't be baked into ahead of time -- so the entrypoint is
 * overridden to a shell that generates that file from the already-injected
 * AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY env vars at container *boot*, then execs the real
 * server against it. The same unsigned request then gets a 403 AccessDenied instead.
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
                // Overrides the image's own ENTRYPOINT (the `weed` binary directly, which treats
                // "sh" as an unknown subcommand rather than running it) so the identity file below
                // can be generated at boot, after Compose has already injected the real env vars.
                'entrypoint' => ['/bin/sh', '-c'],
                // $$, not $ -- the same class of expression ship.json's own `processes` commands
                // also have to escape: Compose interpolates `$VAR` in a command string itself,
                // the same as `${VAR}`, so a single `$` here would resolve against the *host's*
                // environment (empty, almost always) before the container's own shell ever ran,
                // generating the identity file with both credentials blank. `$$` passes a literal
                // `$AWS_...` through for the shell to resolve instead.
                //
                // Escaped through sed before being substituted in -- the raw credential values
                // go straight into the `%s` placeholders otherwise, with no JSON escaping at
                // all, so a secret containing a literal `"` or `\` (nothing stops a real password
                // manager or RequiredEnv-required .env.production value from generating one)
                // would produce invalid JSON, breaking S3 auth entirely rather than just granting
                // the wrong credential. sed, not jq -- this image has the former, not the latter.
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
                    // Must match environmentVariables() below exactly, or "app" authenticates
                    // with credentials this identity file never actually grants. The secret key
                    // is required (not just overridable) in production -- see RequiredEnv's own
                    // docblock -- the access key id stays a plain default, same reasoning
                    // DB_USERNAME is never required either.
                    'AWS_ACCESS_KEY_ID' => "\${{$prefix}AWS_ACCESS_KEY_ID:-ship}",
                    'AWS_SECRET_ACCESS_KEY' => RequiredEnv::expr("{$prefix}AWS_SECRET_ACCESS_KEY", 'shipsecret', $environment),
                ],
                // Persisted in both environments -- see MySqlService's own comment for why.
                'volumes' => ["ship-{$name}-data:/data"],
                'healthcheck' => [
                    // 127.0.0.1, not "localhost" -- this image's resolver tries ::1 first, which
                    // nothing listens on, so wget reports connection refused and the container
                    // never reports healthy despite the master API working fine on IPv4.
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

        // Generic AWS SDK-standard names, not framework-specific, so any
        // S3 client (Laravel's Storage facade, aws-sdk-php directly,
        // Flysystem, ...) can consume them the same way.
        //
        // The credentials are Compose expressions here, not literals -- environment: always wins
        // over env_file:, so a hardcoded "ship"/"shipsecret" would reach the app container
        // regardless of what .env.production actually sets, same bug RequiredEnv's own docblock
        // already covers for every other credentialed service. Same `:-default` composeFragment()
        // itself uses, not RequiredEnv::expr()'s `:?` -- this method has no $environment to vary
        // by (see that docblock for why it doesn't need one): Compose interpolates the whole file
        // in one pass, so composeFragment()'s own `:?` already aborts the entire command before
        // this fallback would ever be reached for real in production.
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
