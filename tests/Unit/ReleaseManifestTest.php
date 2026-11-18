<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\ReleaseManifest;

/**
 * The pure parts of release.json. ReleaseWriterTest covers the manifest as written.
 */
final class ReleaseManifestTest extends TestCase
{
    /**
     * `$$` is Compose's escape for a literal `$` (a processes command's own shell variable), so
     * what follows it is not a variable the deployer must supply.
     */
    public function test_referenced_variables_cover_every_interpolation_form_and_skip_escaped_dollars(): void
    {
        $yaml = <<<'YAML'
            services:
              app:
                environment:
                  DB_PASSWORD: '${DB_PASSWORD:-secret}'
                  MAIL_HOST: '$MAIL_HOST'
                  ROOT: '${DB_ROOT_PASSWORD:?set a real value in .env.production}'
                command: ['sh', '-c', 'echo $$HOME $${SHELL} $$$${NOT_EITHER}']
                ports: ['${APP_PORT:-80}:80']
            YAML;

        self::assertSame(['APP_PORT', 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'MAIL_HOST'], ReleaseManifest::referencedVariables($yaml));
    }

    public function test_named_volumes_exclude_bind_mounts_and_read_the_long_syntax(): void
    {
        $service = ['volumes' => [
            'ship-pgsql-data:/var/lib/postgresql',
            './storage:/var/www/html/storage',
            '/etc/hosts:/etc/hosts:ro',
            '~/cache:/cache',
            ['type' => 'volume', 'source' => 'ship-cache', 'target' => '/cache'],
            ['type' => 'bind', 'source' => '/srv', 'target' => '/srv'],
            '/anonymous',
        ]];

        self::assertSame(['ship-cache', 'ship-pgsql-data'], ReleaseManifest::namedVolumes($service));
        self::assertSame([], ReleaseManifest::namedVolumes([]));
    }

    public function test_created_at_is_utc_with_a_z_suffix_whatever_the_local_time_zone(): void
    {
        $manifest = ReleaseManifest::build(
            app: 'client',
            tag: '1.0.0',
            createdAt: new \DateTimeImmutable('2026-11-17 13:30:00', new \DateTimeZone('Asia/Tehran')),
            builderVersion: '1.0.0',
            source: ['gitCommit' => null, 'dirty' => null],
            platform: 'linux/amd64',
            images: [],
            pulledImages: [],
            releaseServices: [],
            roles: [],
            http: [],
            framework: null,
            runtime: 'php-fpm',
            envFile: null,
            envKeys: [],
            envReferenced: [],
            envRequired: [],
            deploy: null,
            networks: [],
        );

        self::assertSame('2026-11-17T10:00:00Z', $manifest['createdAt']);
        self::assertSame('ship-release', $manifest['format']);
        self::assertSame(['package' => 'php-ship/ship', 'version' => '1.0.0'], $manifest['builder']);
        self::assertNull($manifest['deploy']);
        self::assertSame(['networks' => []], $manifest['requires']);
    }
}
