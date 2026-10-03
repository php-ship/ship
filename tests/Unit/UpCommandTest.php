<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\UpCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

final class UpCommandTest extends TestCase
{
    /**
     * `docker compose port` doesn't fail or print nothing when a container's port mapping exists
     * but the actual host bind never succeeded -- it prints the literal "invalid IP:0". Treating
     * any non-empty output as "bound" (as an earlier version of this check did) misses that case
     * entirely, silently reporting `ship up` as successful when a service is actually unreachable.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function portOutputCases(): iterable
    {
        yield 'a real bound port' => ['0.0.0.0:8080', true];
        yield 'a real bound port on a specific interface' => ['127.0.0.1:8080', true];
        yield 'empty output' => ['', false];
        yield 'the "invalid IP:0" sentinel a failed bind actually prints' => ['invalid IP:0', false];
    }

    #[DataProvider('portOutputCases')]
    public function test_it_tells_a_genuinely_bound_port_from_a_failed_ones_sentinel_output(
        string $output,
        bool $expectedBound,
    ): void {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'looksActuallyBound');

        self::assertSame($expectedBound, $method->invoke($command, $output));
    }

    /**
     * Regression coverage for a real bug found via an independent audit: a flat "15 attempts x
     * 2s = ~30s" wait was well past MySQL/Postgres/Redis's own interval x retries when it was
     * written, but Garage/RustFS/Silo's own healthcheck (10s start_period + 5s x 10 retries = 60s)
     * can legitimately still be "starting" well after that budget, producing a false "never
     * became healthy" on a slow first boot. The wait is computed from each service's own
     * generated healthcheck instead, so it's never shorter than Docker's own patience for it.
     *
     * @return iterable<string, array{array{interval?: string, retries?: int, start_period?: string}, int}>
     */
    public static function healthcheckBudgetCases(): iterable
    {
        yield 'MySQL/Postgres/Redis-shaped (5s interval, 5 retries, no start_period)' => [
            ['interval' => '5s', 'retries' => 5],
            0 + (5 * 5) + 5,
        ];
        yield 'Garage/RustFS/Silo-shaped (10s start_period, 5s interval, 10 retries)' => [
            ['interval' => '5s', 'retries' => 10, 'start_period' => '10s'],
            10 + (5 * 10) + 5,
        ];
        yield 'SeaweedFS-shaped (10s interval, 5 retries, no start_period)' => [
            ['interval' => '10s', 'retries' => 5],
            0 + (10 * 5) + 5,
        ];
        yield 'missing fields fall back to Docker-like defaults' => [
            [],
            0 + (30 * 3) + 5,
        ];
    }

    /**
     * @param array{interval?: string, retries?: int, start_period?: string} $healthcheck
     */
    #[DataProvider('healthcheckBudgetCases')]
    public function test_the_healthcheck_wait_budget_is_computed_from_the_services_own_healthcheck(array $healthcheck, int $expectedSeconds): void
    {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'healthcheckBudgetSeconds');

        self::assertSame($expectedSeconds, $method->invoke($command, $healthcheck));
    }

    /**
     * Ship itself only ever generates a plain "<N>s" duration. A hand-edited
     * docker-compose.override.yml's own healthcheck could use Docker's fuller duration syntax
     * ("1m30s", "1h") instead -- falls back to a conservative 30s rather than miscalculating
     * silently or crashing on something this was never meant to fully parse.
     */
    public function test_parse_seconds_falls_back_to_30_for_a_duration_it_does_not_recognize(): void
    {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'parseSeconds');

        self::assertSame(30, $method->invoke($command, '1m30s'));
    }

    /**
     * Never re-publishes ship/ on its own (see the method's own docblock for why -- a project may
     * have hand-edited those files), only warns -- so this checks it produces the right warning
     * text rather than any side effect.
     */
    public function test_it_warns_when_the_recorded_stub_version_differs_from_whats_installed(): void
    {
        $projectRoot = $this->makeProjectRootWithRecordedVersion('some-other-version-1.2.3');

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        (new \ReflectionMethod($command, 'warnAboutStubVersionMismatch'))->invoke($command, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertStringContainsString('some-other-version-1.2.3', $output->fetch());
    }

    public function test_it_stays_quiet_when_the_recorded_version_matches(): void
    {
        $projectRoot = $this->makeProjectRootWithRecordedVersion((string) ShipVersion::current());

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        (new \ReflectionMethod($command, 'warnAboutStubVersionMismatch'))->invoke($command, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertSame('', $output->fetch());
    }

    public function test_it_stays_quiet_when_no_version_was_ever_recorded(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-up-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot . '/ship', recursive: true);

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        (new \ReflectionMethod($command, 'warnAboutStubVersionMismatch'))->invoke($command, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertSame('', $output->fetch());
    }

    private function makeProjectRootWithRecordedVersion(string $version): string
    {
        $projectRoot = sys_get_temp_dir() . '/ship-up-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot . '/ship', recursive: true);
        file_put_contents($projectRoot . '/ship/.ship-version', $version . "\n");

        return $projectRoot;
    }

    /**
     * Regression coverage for a real bug found via an independent re-audit: renaming the app
     * service (ship.json's serviceNames) only ever rewrites the published ship/nginx/default.conf
     * at `ship init` time -- hand-editing serviceNames afterward, without re-running `ship init`,
     * left that file pointing at the old name while ComposeFileBuilder renames the actual compose
     * service on every `ship up`, with nothing ever warning about the mismatch.
     */
    public function test_it_warns_when_the_published_nginx_upstream_no_longer_matches_service_names(): void
    {
        $projectRoot = $this->makeProjectRootWithPublishedNginxUpstream('app');

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        $config = new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']);
        (new \ReflectionMethod($command, 'warnAboutNginxUpstreamMismatch'))->invoke($command, $config, $output);

        (new Filesystem())->remove($projectRoot);

        $written = $output->fetch();
        self::assertStringContainsString('"app"', $written);
        self::assertStringContainsString('"client-app"', $written);
    }

    public function test_it_stays_quiet_when_the_published_nginx_upstream_already_matches(): void
    {
        $projectRoot = $this->makeProjectRootWithPublishedNginxUpstream('client-app');

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        $config = new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']);
        (new \ReflectionMethod($command, 'warnAboutNginxUpstreamMismatch'))->invoke($command, $config, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertSame('', $output->fetch());
    }

    public function test_it_stays_quiet_when_no_nginx_config_was_ever_published(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-up-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, recursive: true);

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        $config = new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']);
        (new \ReflectionMethod($command, 'warnAboutNginxUpstreamMismatch'))->invoke($command, $config, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertSame('', $output->fetch());
    }

    private function makeProjectRootWithPublishedNginxUpstream(string $appServiceName): string
    {
        $projectRoot = sys_get_temp_dir() . '/ship-up-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot . '/ship/nginx', recursive: true);
        file_put_contents(
            $projectRoot . '/ship/nginx/default.conf',
            "location = /index.php {\n    set \$upstream_app {$appServiceName}:9000;\n}\n",
        );

        return $projectRoot;
    }

    /**
     * FrankenPHP was excluded from hostUser once (its Debian image had no non-root setup at all),
     * raised again once that gap was closed -- this just has to no longer be special-cased:
     * whatever resolveHostUser decides for it has to match a plain Swoole config given the same
     * inputs, not its own distinct ("FrankenPHP image is not supported yet") rejection reason.
     */
    public function test_frankenphp_is_no_longer_excluded_from_host_user(): void
    {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'resolveHostUser');

        $frankenConfig = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-frankenphp'], hostUser: true);
        $swooleConfig = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-swoole'], hostUser: true);

        $frankenOutput = new BufferedOutput();
        $frankenResult = $method->invoke($command, $frankenConfig, false, $frankenOutput);

        $swooleOutput = new BufferedOutput();
        $swooleResult = $method->invoke($command, $swooleConfig, false, $swooleOutput);

        self::assertSame($swooleResult, $frankenResult);
        self::assertSame($swooleOutput->fetch(), $frankenOutput->fetch());
    }
}
