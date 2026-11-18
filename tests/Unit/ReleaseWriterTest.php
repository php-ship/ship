<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\ProductionImagePlan;
use Ship\Docker\ReleaseDocker;
use Ship\Docker\ReleaseFailed;
use Ship\Docker\ReleaseWriter;
use Ship\Frameworks\LaravelAdapter;
use Ship\Runtime\ProcessRunner;
use Ship\Services\CentrifugoService;
use Ship\Services\GarageService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\RedisService;
use Ship\Services\ReverbService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The release folder and release.json (the ship-release contract), with Docker replaced by
 * FakeReleaseDocker. The real Docker path is covered by CI's docker-build job.
 */
final class ReleaseWriterTest extends TestCase
{
    /**
     * Values from the fixture .env.production; none may appear in release.json.
     */
    private const SECRETS = [
        'APP_KEY' => 'base64:c2hpcC1zZWNyZXQtYXBwLWtleS12YWx1ZQ==',
        'DB_PASSWORD' => 'pg-secret-7f3a',
        'STRIPE_KEY' => 'sk_live_shipfixture',
        'APP_URL' => 'https://client.example.test',
    ];

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-release-writer-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/bootstrap', recursive: true);
        file_put_contents($this->projectRoot . '/artisan', "<?php\n");
        file_put_contents($this->projectRoot . '/composer.json', "{}\n");
        file_put_contents($this->projectRoot . '/bootstrap/app.php', "<?php\nreturn Application::configure()->withRouting(web: 'x', health: '/up');\n");

        $env = '';
        foreach (self::SECRETS as $key => $value) {
            $env .= "{$key}={$value}\n";
        }
        file_put_contents($this->projectRoot . '/.env.production', $env);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry([new PostgresService(), new RedisService(), new ReverbService(), new CentrifugoService(), new OctaneSwooleService(), new GarageService()]);
    }

    private function config(array $overrides = []): ShipConfig
    {
        $arguments = [
            'phpVersion' => '8.4',
            'services' => ['database' => 'postgres', 'cache' => 'redis', 'broadcasting' => 'reverb'],
            'name' => 'client',
            'processes' => ['horizon' => 'php artisan horizon'],
            'deployCommands' => ['php artisan migrate --force', "echo 'it''s \$HOME' && printf '%s\\n' \"a b\""],
            'externalNetwork' => 'proxy',
            ...$overrides,
        ];

        return new ShipConfig(...$arguments);
    }

    /**
     * What ProductionBuildRunner produces: the production compose file with image tags, and the
     * images it built.
     *
     * @return array{yaml: string, images: list<array{tag: string, canonicalService: string, members: list<string>}>}
     */
    private function built(ShipConfig $config, string $tag = '1.5.0'): array
    {
        $yaml = (new ComposeFileBuilder($this->registry()))->build($config, ShipEnvironment::Production, projectName: 'client');
        $parsed = Yaml::parse($yaml);
        $plan = ProductionImagePlan::plan($parsed['services'], 'client', $config->serviceNames['app'] ?? 'app', $tag);
        $parsed['services'] = $plan['services'];

        return ['yaml' => Yaml::dump($parsed, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE), 'images' => $plan['images']];
    }

    /**
     * @return array{destination: string, manifest: array<string, mixed>, json: string}
     */
    private function release(
        ?ShipConfig $config = null,
        ?FakeReleaseDocker $docker = null,
        bool $force = false,
        bool $withEnv = true,
        string $tag = '1.5.0',
    ): array {
        $config ??= $this->config();
        $built = $this->built($config, $tag);

        $destination = (new ReleaseWriter($docker ?? new FakeReleaseDocker(), $this->registry()))->write(
            projectRoot: $this->projectRoot,
            tag: $tag,
            config: $config,
            projectName: 'client',
            productionComposeYaml: $built['yaml'],
            images: $built['images'],
            source: ['gitCommit' => str_repeat('a', 40), 'dirty' => false],
            framework: new LaravelAdapter(),
            force: $force,
            withEnv: $withEnv,
            output: new NullOutput(),
        );

        $json = (string) file_get_contents($destination . '/release.json');

        return ['destination' => $destination, 'manifest' => json_decode($json, true, flags: JSON_THROW_ON_ERROR), 'json' => $json];
    }

    public function test_release_json_validates_against_the_contract_schema(): void
    {
        $json = $this->release()['json'];

        $validator = new Validator();
        $validator->setMaxErrors(20);
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/ship-release.schema.json');
        $result = $validator->validate(json_decode($json), $schema);

        $error = $result->error();

        self::assertNull($error, $error === null ? '' : (string) json_encode((new ErrorFormatter())->format($error), JSON_PRETTY_PRINT));
    }

    /**
     * The schema itself must reject what the contract forbids, or the test above proves nothing.
     */
    public function test_the_schema_rejects_a_release_json_missing_a_field_or_with_a_wrong_format(): void
    {
        $manifest = json_decode($this->release()['json']);
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/ship-release.schema.json');

        $withoutHttp = clone $manifest;
        unset($withoutHttp->http);
        $wrongFormat = clone $manifest;
        $wrongFormat->format = 'ship-release-2';

        self::assertFalse((new Validator())->validate($withoutHttp, $schema)->isValid());
        self::assertFalse((new Validator())->validate($wrongFormat, $schema)->isValid());
    }

    public function test_no_production_setting_value_appears_anywhere_in_release_json(): void
    {
        $json = $this->release()['json'];

        foreach (self::SECRETS as $value) {
            self::assertStringNotContainsString($value, $json);
            self::assertStringNotContainsString(substr($value, 0, 12), $json);
        }
    }

    public function test_app_equals_the_compose_project_name(): void
    {
        $release = $this->release();
        $compose = Yaml::parseFile($release['destination'] . '/docker-compose.yml');

        self::assertSame('client', $release['manifest']['app']);
        self::assertSame($release['manifest']['app'], $compose['name']);
    }

    public function test_every_list_is_sorted(): void
    {
        $manifest = $this->release()['manifest'];

        $lists = [
            $manifest['env']['keys'],
            $manifest['env']['referenced'],
            $manifest['env']['required'],
            $manifest['deploy']['needs'],
            $manifest['requires']['networks'],
            array_keys($manifest['services']),
            ...array_column($manifest['images'], 'services'),
            ...array_column($manifest['pulledImages'], 'services'),
            ...array_column($manifest['services'], 'volumes'),
        ];

        foreach ($lists as $list) {
            $sorted = $list;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $list);
        }
    }

    public function test_env_lists_names_only_and_required_is_a_subset_of_keys(): void
    {
        $env = $this->release()['manifest']['env'];

        self::assertSame('.env', $env['file']);
        self::assertSame(['APP_KEY', 'APP_URL', 'DB_PASSWORD', 'STRIPE_KEY'], $env['keys']);
        // DB_PASSWORD from RequiredEnv, APP_KEY from the Laravel adapter.
        self::assertSame(['APP_KEY', 'DB_PASSWORD'], $env['required']);
        self::assertSame([], array_diff($env['required'], $env['keys']));
        // Interpolated by Compose; a processes command's escaped $$ variables are not.
        self::assertContains('DB_PASSWORD', $env['referenced']);
        self::assertContains('DB_USERNAME', $env['referenced']);
        self::assertNotContains('HOME', $env['referenced']);
    }

    public function test_http_lists_the_nginx_site_with_its_health_path_and_reverb(): void
    {
        self::assertSame([
            ['service' => 'webserver', 'port' => 80, 'role' => 'site', 'health' => '/up', 'urlSetting' => 'APP_URL'],
            ['service' => 'reverb', 'port' => 8080, 'role' => 'websocket', 'health' => null, 'urlSetting' => 'VITE_REVERB_HOST'],
        ], $this->release()['manifest']['http']);
    }

    /**
     * A service's own health path (Centrifugo's /health) reaches release.json; so do its required
     * settings, including the allowed origins it can't serve browsers without.
     */
    public function test_centrifugo_is_a_websocket_entry_with_its_own_health_path(): void
    {
        $manifest = $this->release($this->config(['services' => ['database' => 'postgres', 'broadcasting' => 'centrifugo']]))['manifest'];

        self::assertSame(['service' => 'centrifugo', 'port' => 8000, 'role' => 'websocket', 'health' => '/health', 'urlSetting' => 'VITE_CENTRIFUGO_URL'], $manifest['http'][1]);
        self::assertSame('infrastructure', $manifest['services']['centrifugo']['role']);
        self::assertStringStartsWith('centrifugo/centrifugo:v', $manifest['services']['centrifugo']['image']);
        foreach (['CENTRIFUGO_ALLOWED_ORIGINS', 'CENTRIFUGO_API_KEY', 'CENTRIFUGO_TOKEN_SECRET'] as $required) {
            self::assertContains($required, $manifest['env']['required']);
        }
    }

    public function test_http_lists_app_on_octanes_port(): void
    {
        $config = $this->config(['services' => ['database' => 'postgres', 'runtime' => 'octane-swoole']]);

        $manifest = $this->release($config)['manifest'];

        self::assertSame([['service' => 'app', 'port' => 8000, 'role' => 'site', 'health' => '/up', 'urlSetting' => 'APP_URL']], $manifest['http']);
        self::assertSame('octane-swoole', $manifest['runtime']);
    }

    public function test_the_health_path_is_null_when_the_app_defines_none(): void
    {
        file_put_contents($this->projectRoot . '/bootstrap/app.php', "<?php\nreturn Application::configure()->withRouting(web: 'x');\n");

        self::assertNull($this->release()['manifest']['http'][0]['health']);
    }

    public function test_services_record_role_image_healthcheck_and_volumes(): void
    {
        $manifest = $this->release()['manifest'];
        $services = $manifest['services'];

        self::assertSame(['app', 'horizon', 'postgres', 'redis', 'reverb', 'webserver'], array_keys($services));
        self::assertSame('app', $services['app']['role']);
        self::assertSame('web', $services['webserver']['role']);
        self::assertSame('process', $services['horizon']['role']);
        self::assertSame('process', $services['reverb']['role']);
        self::assertSame('infrastructure', $services['postgres']['role']);
        self::assertSame(['ship-postgres-data'], $services['postgres']['volumes']);
        self::assertTrue($services['postgres']['healthcheck']);
        self::assertSame([], $services['webserver']['volumes']);

        $refs = [...array_column($manifest['images'], 'ref'), ...array_column($manifest['pulledImages'], 'ref')];
        foreach ($services as $name => $service) {
            self::assertContains($service['image'], $refs, $name);
        }
    }

    /**
     * Garage is built by ship, but it is infrastructure, not part of the app.
     */
    public function test_a_built_infrastructure_service_is_still_infrastructure(): void
    {
        $manifest = $this->release($this->config(['services' => ['storage' => 'garage'], 'deployCommands' => ['true']]))['manifest'];

        self::assertSame('infrastructure', $manifest['services']['garage']['role']);
        self::assertContains('client-garage:1.5.0', array_column($manifest['images'], 'ref'));
        self::assertContains('garage', $manifest['deploy']['needs']);
    }

    public function test_pulled_images_are_pinned_by_digest_in_both_the_compose_file_and_release_json(): void
    {
        $release = $this->release();
        $compose = Yaml::parseFile($release['destination'] . '/docker-compose.yml');

        self::assertSame([
            ['ref' => 'postgres:18-alpine@sha256:' . FakeReleaseDocker::digestFor('postgres:18-alpine'), 'services' => ['postgres']],
            ['ref' => 'redis:8-alpine@sha256:' . FakeReleaseDocker::digestFor('redis:8-alpine'), 'services' => ['redis']],
        ], $release['manifest']['pulledImages']);
        self::assertSame($release['manifest']['pulledImages'][0]['ref'], $compose['services']['postgres']['image']);
        self::assertArrayNotHasKey('build', $compose['services']['app']);
    }

    public function test_an_image_without_a_repository_digest_fails_and_writes_nothing(): void
    {
        $docker = new FakeReleaseDocker(withoutDigest: ['redis:8-alpine']);

        try {
            $this->release(docker: $docker);
            self::fail('Expected the release to fail.');
        } catch (ReleaseFailed $e) {
            self::assertStringContainsString('redis:8-alpine has no repository digest', $e->getMessage());
        }

        $this->assertNoReleaseOrStaging('1.5.0');
    }

    public function test_built_images_record_their_id_archive_and_checksum(): void
    {
        $release = $this->release();
        $app = $release['manifest']['images'][0];

        self::assertSame('client-app:1.5.0', $app['ref']);
        self::assertSame(FakeReleaseDocker::idFor('client-app:1.5.0'), $app['id']);
        self::assertSame('images/app.tar', $app['file']);
        self::assertSame(hash_file('sha256', $release['destination'] . '/images/app.tar'), $app['sha256']);
        self::assertSame(['app', 'horizon', 'reverb'], $app['services']);
        self::assertSame('linux/amd64', $release['manifest']['platform']);
    }

    public function test_images_for_different_platforms_fail_the_release(): void
    {
        $docker = new FakeReleaseDocker(platforms: ['client-webserver:1.5.0' => 'linux/arm64']);

        $this->expectException(ReleaseFailed::class);
        $this->expectExceptionMessage('different platforms');

        $this->release(docker: $docker);
    }

    public function test_a_failing_image_export_leaves_no_release_and_no_staging_folder(): void
    {
        $docker = new FakeReleaseDocker(failSave: 'client-webserver:1.5.0');

        try {
            $this->release(docker: $docker);
            self::fail('Expected the release to fail.');
        } catch (ReleaseFailed $e) {
            self::assertStringContainsString('exporting client-webserver:1.5.0 failed', $e->getMessage());
        }

        $this->assertNoReleaseOrStaging('1.5.0');
    }

    public function test_an_existing_tag_is_refused_without_force_and_replaced_with_it(): void
    {
        $first = $this->release();
        file_put_contents($first['destination'] . '/marker', 'old');

        try {
            $this->release();
            self::fail('Expected an existing tag to be refused.');
        } catch (ReleaseFailed $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }
        self::assertFileExists($first['destination'] . '/marker');

        $second = $this->release(force: true);

        self::assertSame($first['destination'], $second['destination']);
        self::assertFileDoesNotExist($second['destination'] . '/marker');
        self::assertFileExists($second['destination'] . '/release.json');
        self::assertSame([], glob($this->projectRoot . '/dist/ship/.release*') ?: []);
    }

    /**
     * A failed --force replacement keeps the old release.
     */
    public function test_a_failed_forced_release_keeps_the_existing_one(): void
    {
        $first = $this->release();

        try {
            $this->release(docker: new FakeReleaseDocker(failSave: 'client-app:1.5.0'), force: true);
            self::fail('Expected the release to fail.');
        } catch (ReleaseFailed) {
        }

        self::assertSame($first['json'], file_get_contents($first['destination'] . '/release.json'));
        self::assertSame([], glob($this->projectRoot . '/dist/ship/.release*') ?: []);
    }

    public function test_a_symlinked_destination_is_refused_even_with_force(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Creating symlinks needs elevated rights on Windows.');
        }

        mkdir($this->projectRoot . '/elsewhere');
        mkdir($this->projectRoot . '/dist/ship', recursive: true);
        symlink($this->projectRoot . '/elsewhere', $this->projectRoot . '/dist/ship/1.5.0');

        $this->expectException(ReleaseFailed::class);
        $this->expectExceptionMessage('symlink');

        $this->release(force: true);
    }

    public function test_no_env_writes_no_env_file_but_still_lists_the_settings(): void
    {
        $release = $this->release(withEnv: false);

        self::assertFileDoesNotExist($release['destination'] . '/.env');
        self::assertNull($release['manifest']['env']['file']);
        self::assertSame(['APP_KEY', 'APP_URL', 'DB_PASSWORD', 'STRIPE_KEY'], $release['manifest']['env']['keys']);
        self::assertStringContainsString('--no-env', (string) file_get_contents($release['destination'] . '/README.md'));
    }

    public function test_deploy_is_null_without_deploy_commands_and_no_script_is_written(): void
    {
        $release = $this->release($this->config(['deployCommands' => []]));

        self::assertNull($release['manifest']['deploy']);
        self::assertFileDoesNotExist($release['destination'] . '/deploy-commands.sh');
    }

    public function test_deploy_records_the_app_service_its_needs_and_argv_commands(): void
    {
        self::assertSame([
            'service' => 'app',
            'needs' => ['postgres', 'redis'],
            'commands' => [
                ['sh', '-c', 'php artisan migrate --force'],
                ['sh', '-c', "echo 'it''s \$HOME' && printf '%s\\n' \"a b\""],
            ],
        ], $this->release()['manifest']['deploy']);
    }

    /**
     * Runs the generated script with a stand-in `docker` that prints its arguments, one per line,
     * and compares them with release.json's `deploy`: the script runs exactly what it describes.
     */
    public function test_deploy_commands_sh_runs_exactly_the_deploy_object(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Needs a POSIX shell.');
        }

        $release = $this->release();
        $deploy = $release['manifest']['deploy'];

        $bin = $this->projectRoot . '/fake-bin';
        mkdir($bin);
        file_put_contents($bin . '/docker', "#!/bin/sh\nprintf '%s\\037' \"\$@\"\nprintf '\\036'\n");
        chmod($bin . '/docker', 0o755);

        $process = new \Symfony\Component\Process\Process(['sh', 'deploy-commands.sh'], $release['destination'], ['PATH' => $bin . ':' . getenv('PATH')]);
        $process->mustRun();

        $invocations = array_map(
            static fn (string $call): array => explode("\x1f", rtrim($call, "\x1f")),
            array_values(array_filter(explode("\x1e", $process->getOutput()), static fn (string $call): bool => $call !== '')),
        );

        $expected = [['compose', 'up', '-d', '--wait', ...$deploy['needs']]];
        foreach ($deploy['commands'] as $argv) {
            $expected[] = ['compose', 'run', '--rm', '--no-deps', '-T', $deploy['service'], ...$argv];
        }

        self::assertSame($expected, $invocations);
    }

    public function test_the_folder_and_its_files_have_the_contract_permissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows file access is controlled by ACLs rather than POSIX modes.');
        }

        $destination = $this->release()['destination'];

        self::assertSame(0o700, fileperms($destination) & 0o777);
        self::assertSame(0o600, fileperms($destination . '/.env') & 0o777);
        self::assertSame(0o755, fileperms($destination . '/deploy-commands.sh') & 0o777);
        self::assertSame(file_get_contents($this->projectRoot . '/.env.production'), file_get_contents($destination . '/.env'));
    }

    public function test_the_readme_lists_the_exact_manual_steps(): void
    {
        $readme = (string) file_get_contents($this->release()['destination'] . '/README.md');

        self::assertStringContainsString(
            "docker network create proxy  # only if it does not exist yet\ndocker load -i images/app.tar\n"
                . "docker load -i images/webserver.tar\nsh deploy-commands.sh\ndocker compose up -d\n",
            $readme,
        );
        self::assertStringContainsString('production secrets', $readme);
    }

    public function test_requires_lists_the_external_network(): void
    {
        self::assertSame(['networks' => ['proxy']], $this->release()['manifest']['requires']);
    }

    private function assertNoReleaseOrStaging(string $tag): void
    {
        self::assertDirectoryDoesNotExist($this->projectRoot . '/dist/ship/' . $tag);
        self::assertSame([], glob($this->projectRoot . '/dist/ship/.release*') ?: []);
    }
}

/**
 * Deterministic stand-in for Docker.
 */
final class FakeReleaseDocker extends ReleaseDocker
{
    /**
     * @param list<string> $withoutDigest
     * @param array<string, string> $platforms
     */
    public function __construct(
        private readonly array $withoutDigest = [],
        private readonly array $platforms = [],
        private readonly ?string $failSave = null,
    ) {
        parent::__construct(new ProcessRunner(), sys_get_temp_dir());
    }

    public static function digestFor(string $ref): string
    {
        return hash('sha256', 'digest:' . $ref);
    }

    public static function idFor(string $ref): string
    {
        return 'sha256:' . hash('sha256', 'id:' . $ref);
    }

    public function pull(string $ref): bool
    {
        return true;
    }

    public function repoDigest(string $ref): ?string
    {
        return in_array($ref, $this->withoutDigest, true) ? null : 'sha256:' . self::digestFor($ref);
    }

    public function imageId(string $ref): ?string
    {
        return self::idFor($ref);
    }

    public function platform(string $ref): ?string
    {
        return $this->platforms[$ref] ?? 'linux/amd64';
    }

    public function save(string $ref, string $path): bool
    {
        if ($ref === $this->failSave) {
            return false;
        }

        file_put_contents($path, "fake archive of {$ref}\n");

        return true;
    }
}
