<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\ConfigTestCommand;
use Ship\Console\Commands\InitCommand;
use Ship\Docker\EnvFile;
use Ship\Docker\ProjectEnv;
use Ship\Node\PackageManagerVersions;
use Ship\Services\ServiceRegistry;
use Ship\Support\StubRevision;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The settings the project's services use, added to its env files by `ship init` where missing,
 * and reported by `ship config:test`.
 */
final class ProjectEnvTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-project-env-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry(ServiceRegistry::defaults());
    }

    private function read(string $file): string
    {
        return (string) @file_get_contents($this->projectRoot . '/' . $file);
    }

    /**
     * Fixed values as they are, a value that follows the file as its default, one built from
     * others as references the dotenv readers expand.
     */
    public function test_values_are_written_the_way_the_containers_use_them(): void
    {
        $values = ProjectEnv::development(new ShipConfig(phpVersion: '8.4', services: ['database' => 'sqlserver', 'broadcasting' => 'reverb']), $this->registry());

        self::assertSame('sqlserver', $values['DB_HOST']);
        self::assertSame('ShipSecret123', $values['DB_PASSWORD']);
        self::assertSame('sqlsrv://${DB_USERNAME}:${DB_PASSWORD}@sqlserver:1433/${DB_DATABASE}?trust_server_certificate=true', $values['DB_URL']);
        self::assertSame('${REVERB_PORT}', $values['VITE_REVERB_PORT']);

        ProjectEnv::append($this->projectRoot . '/.env', 'test', ['DB_URL' => $values['DB_URL'], 'A' => 'plain']);
        self::assertStringContainsString("DB_URL=\"sqlsrv://\${DB_USERNAME}:\${DB_PASSWORD}@sqlserver:1433/\${DB_DATABASE}?trust_server_certificate=true\"\nA=plain\n", $this->read('.env'));
    }

    /**
     * A key that's only commented out (Laravel's skeleton has `# DB_HOST=127.0.0.1`) is missing.
     */
    public function test_a_commented_out_key_is_missing(): void
    {
        file_put_contents($this->projectRoot . '/.env', "DB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n");

        self::assertSame(['DB_HOST'], ProjectEnv::missingFrom($this->projectRoot . '/.env', ['DB_CONNECTION', 'DB_HOST']));
    }

    private function init(): CommandTester
    {
        $tester = new CommandTester(new InitCommand($this->projectRoot, $this->registry(), new PackageManagerVersions(static fn (string $url): ?string => null)));
        // Name; PostgreSQL and Redis; no instances, processes, extensions or networks; PHP; Node.
        $tester->setInputs(['', 'Database,Cache', 'PostgreSQL', 'Redis', 'no', 'no', '', '', '8.4', '']);
        $tester->execute([]);

        return $tester;
    }

    /**
     * Only missing keys are added, at the end, under a comment; existing values stay, and a
     * conflicting fixed value is pointed out, not changed.
     */
    public function test_init_adds_what_is_missing_and_changes_nothing(): void
    {
        file_put_contents($this->projectRoot . '/.env', "APP_KEY=base64:abc\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\nDB_PASSWORD=mine\n");

        $tester = $this->init();

        $env = EnvFile::parse($this->projectRoot . '/.env');
        self::assertSame('sqlite', $env['DB_CONNECTION']);
        self::assertSame('mine', $env['DB_PASSWORD']);
        self::assertSame('postgres', $env['DB_HOST']);
        self::assertSame('redis', $env['REDIS_HOST']);
        self::assertStringStartsWith("APP_KEY=base64:abc\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\nDB_PASSWORD=mine\n\n# Added by ship init for PostgreSQL, Redis\n", $this->read('.env'));
        self::assertStringContainsString('DB_CONNECTION=sqlite, but ship\'s containers use DB_CONNECTION=pgsql', (string) preg_replace('/\s*\n\s*!?\s*/', ' ', $tester->getDisplay()));

        // .env.example didn't exist: created with every key.
        self::assertSame('pgsql', EnvFile::parse($this->projectRoot . '/.env.example')['DB_CONNECTION']);

        // .env.production: what a release requires, empty.
        self::assertSame(['DB_PASSWORD' => ''], EnvFile::parse($this->projectRoot . '/.env.production'));
    }

    public function test_re_running_init_adds_nothing_twice(): void
    {
        $this->init();
        $before = [$this->read('.env'), $this->read('.env.example'), $this->read('.env.production')];

        $this->init();

        self::assertSame($before, [$this->read('.env'), $this->read('.env.example'), $this->read('.env.production')]);
        self::assertSame(1, substr_count($this->read('.env'), '# Added by ship init'));
    }

    /**
     * Without a .env, it's .env.example's copy, as a framework's own setup would make it.
     */
    public function test_a_missing_env_is_created_from_the_example(): void
    {
        file_put_contents($this->projectRoot . '/.env.example', "APP_NAME=Demo\n");

        $this->init();

        self::assertSame($this->read('.env.example'), $this->read('.env'));
        self::assertStringStartsWith("APP_NAME=Demo\n\n# Added by ship init", $this->read('.env'));
    }

    /**
     * Warnings, not problems: a CI run without the env files still passes on them.
     */
    public function test_config_test_warns_about_missing_and_conflicting_settings(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'postgres']))->toFile($this->projectRoot . '/ship.json');
        file_put_contents($this->projectRoot . '/.env', "DB_CONNECTION=sqlite\n");
        file_put_contents($this->projectRoot . '/.env.production', "DB_PASSWORD=real\n");
        // Published files of the current revision, so only the env files are in question.
        mkdir($this->projectRoot . '/ship');
        file_put_contents($this->projectRoot . '/ship/.stub-revision', StubRevision::REQUIRED . "\n");

        $tester = new CommandTester(new ConfigTestCommand($this->projectRoot, $this->registry()));
        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString(".env doesn't set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD", $display);
        self::assertStringContainsString('.env.example doesn\'t set DB_CONNECTION', $display);
        self::assertStringContainsString('.env sets DB_CONNECTION=sqlite, but ship\'s containers use DB_CONNECTION=pgsql', $display);
        self::assertStringNotContainsString('problem found', $display);
        self::assertSame(0, $exitCode);
    }
}
