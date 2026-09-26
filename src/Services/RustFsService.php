<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Alt driver for storage. Modern Rust rewrite, drop-in S3-compatible -- the actual S3 API is
 * solid, verified live with real bucket create/list/put calls. Its web console is NOT wired up
 * here despite the image shipping one: verified live that it currently returns the same
 * "AccessDenied" XML the S3 API itself returns for an unsigned request, on both `--console-enable`
 * and an explicit `--console-address` flag -- not a misconfiguration on this end, it matches a
 * currently-open upstream bug (rustfs/rustfs#8013). Revisit publishing a console port once that's
 * fixed upstream; until then this is deliberately positioned the same as SeaweedFS/Garage (no
 * console), not as this project's actual console-having alternative -- see SiloService for that.
 *
 * Like Garage, has no built-in default-bucket provisioning of its own -- unlike Garage, also has
 * no equivalent flag for it at all (verified live: a write to a bucket that was never created
 * fails outright with "NoSuchBucket"), so the app's own bucket ("local", see
 * environmentVariables()) has to be created once by hand, via any S3 client, before first use.
 * Same requirement any real S3 bucket has; Garage's auto-provisioning is the outlier here, not
 * this.
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

        return [
            $name => [
                'image' => 'rustfs/rustfs:1.0.0',
                'environment' => [
                    // Must match environmentVariables() below exactly, or "app"
                    // authenticates with credentials RustFS never provisioned.
                    'RUSTFS_ACCESS_KEY' => 'ship',
                    'RUSTFS_SECRET_KEY' => 'shipsecret',
                ],
                'volumes' => $environment->isDevelopment()
                    ? ["ship-{$name}-data:/data"]
                    : [],
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
}
