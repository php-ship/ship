<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Docker\NginxUpstreamMismatch;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Extracted from UpCommand (see that class's own history) so ship build/ship release can share
 * the exact same check -- a real bug found via an independent re-audit: they build the same
 * prod-nginx image from this exact same stale file, so a rename can ship a webserver that can't
 * reach the app either, with nothing warning about it there.
 */
final class NginxUpstreamMismatchTest extends TestCase
{
    public function test_it_warns_when_the_published_nginx_upstream_no_longer_matches_service_names(): void
    {
        $projectRoot = $this->makeProjectRootWithPublishedNginxUpstream('app');
        $config = new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']);

        $warning = NginxUpstreamMismatch::warning($projectRoot, $config);

        (new Filesystem())->remove($projectRoot);

        self::assertNotNull($warning);
        self::assertStringContainsString('"app"', $warning);
        self::assertStringContainsString('"client-app"', $warning);
    }

    public function test_it_stays_quiet_when_the_published_nginx_upstream_already_matches(): void
    {
        $projectRoot = $this->makeProjectRootWithPublishedNginxUpstream('client-app');
        $config = new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']);

        $warning = NginxUpstreamMismatch::warning($projectRoot, $config);

        (new Filesystem())->remove($projectRoot);

        self::assertNull($warning);
    }

    public function test_it_stays_quiet_when_no_nginx_config_was_ever_published(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-nginx-upstream-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, recursive: true);
        $config = new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']);

        $warning = NginxUpstreamMismatch::warning($projectRoot, $config);

        (new Filesystem())->remove($projectRoot);

        self::assertNull($warning);
    }

    private function makeProjectRootWithPublishedNginxUpstream(string $appServiceName): string
    {
        $projectRoot = sys_get_temp_dir() . '/ship-nginx-upstream-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot . '/ship/nginx', recursive: true);
        file_put_contents(
            $projectRoot . '/ship/nginx/default.conf',
            "location = /index.php {\n    set \$upstream_app {$appServiceName}:9000;\n}\n",
        );

        return $projectRoot;
    }
}
