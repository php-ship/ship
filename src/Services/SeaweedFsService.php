<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Runs SeaweedFS's server subcommand -- master, volume, and filer/S3-gateway in one dev process.
 */
final class SeaweedFsService implements ServiceDefinition
{
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

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'seaweedfs' => [
                'image' => 'chrislusf/seaweedfs:4.46',
                'command' => ['server', '-s3', '-s3.port=8333', '-dir=/data'],
                'volumes' => $environment->isDevelopment()
                    ? ['ship-seaweedfs-data:/data']
                    : [],
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

    public function environmentVariables(): array
    {
        // Generic AWS SDK-standard names, not framework-specific, so any
        // S3 client (Laravel's Storage facade, aws-sdk-php directly,
        // Flysystem, ...) can consume them the same way.
        return [
            'AWS_ENDPOINT' => 'http://seaweedfs:8333',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
            'AWS_DEFAULT_REGION' => 'us-east-1',
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
