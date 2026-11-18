<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\ReleaseCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Services\MySqlService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ReleaseCommandTest extends TestCase
{
    public function test_a_valid_tag_given_via_the_option_is_returned_as_is(): void
    {
        self::assertSame('1.2.0', $this->resolveTag(['--tag' => '1.2.0'], interactive: true));
    }

    /**
     * A prompt would hang a non-interactive pipeline, so a missing --tag fails immediately.
     */
    public function test_a_missing_tag_fails_immediately_in_a_non_interactive_session_instead_of_prompting(): void
    {
        $output = new BufferedOutput();

        self::assertNull($this->resolveTag([], interactive: false, output: $output));
        self::assertStringContainsString('--tag is required', $output->fetch());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTags(): iterable
    {
        yield 'empty string' => [''];
        yield 'starts with a dash' => ['-bad'];
        yield 'starts with a dot' => ['.bad'];
        yield 'contains a slash' => ['1.2/0'];
        yield 'contains whitespace' => ['1.2 0'];
    }

    #[DataProvider('invalidTags')]
    public function test_an_invalid_tag_is_rejected_cleanly(string $tag): void
    {
        $output = new BufferedOutput();

        self::assertNull($this->resolveTag(['--tag' => $tag], interactive: true, output: $output));
        self::assertStringContainsString('not a valid release tag', $output->fetch());
    }

    /**
     * chmod() is a no-op on Windows, so deploy-commands.sh built there isn't executable on the
     * server; the warning says so.
     */
    public function test_windows_executable_bit_warning_fires_only_on_windows_with_deploy_commands(): void
    {
        $command = new ReleaseCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'windowsExecutableBitWarning');

        $withCommands = new ShipConfig(phpVersion: '8.4', services: [], deployCommands: ['php artisan migrate']);
        $withoutCommands = new ShipConfig(phpVersion: '8.4', services: []);

        self::assertStringContainsString('chmod +x', (string) $method->invoke($command, $withCommands, 'Windows'));
        self::assertNull($method->invoke($command, $withoutCommands, 'Windows'));
        self::assertNull($method->invoke($command, $withCommands, 'Linux'));
    }

    /**
     * A required production variable missing from .env.production fails before
     * ProductionBuildRunner, and so Docker, is reached.
     */
    public function test_release_fails_fast_on_a_missing_required_production_variable(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-release-env-check-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, recursive: true);
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql'], name: 'client'))->toFile($projectRoot . '/ship.json');
        file_put_contents($projectRoot . '/.env.production', "APP_KEY=base64:something\n");

        $command = new ReleaseCommand($projectRoot, new ProcessRunner(), new ServiceRegistry([new MySqlService()]), []);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--tag' => '1.0.0']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('DB_PASSWORD', $tester->getDisplay());
        self::assertStringContainsString('.env.production', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($projectRoot . '/dist/ship/1.0.0');

        (new Filesystem())->remove($projectRoot);
    }

    /**
     * release.json's `app` and the server's Compose project name must not depend on the folder the
     * release is built in. The message suggests the name the project resolves to today, which keeps
     * an existing deployment's volumes.
     */
    public function test_a_release_requires_a_name_in_ship_json_and_suggests_the_current_one(): void
    {
        $projectRoot = $this->project(new ShipConfig(phpVersion: '8.4', services: []), "APP_KEY=x\n", folder: 'Client App');

        $tester = new CommandTester(new ReleaseCommand($projectRoot, new ProcessRunner(), new ServiceRegistry([]), []));

        self::assertSame(1, $tester->execute(['--tag' => '1.0.0']));
        self::assertStringContainsString('"name": "client-app"', $tester->getDisplay());
        self::assertStringContainsString('new, empty data volumes', $tester->getDisplay());
    }

    /**
     * Refused before the build, which takes minutes.
     */
    public function test_an_existing_tag_is_refused_before_anything_is_built(): void
    {
        $projectRoot = $this->project(new ShipConfig(phpVersion: '8.4', services: [], name: 'client'), "APP_KEY=x\n");
        mkdir($projectRoot . '/dist/ship/1.0.0', recursive: true);

        $tester = new CommandTester(new ReleaseCommand($projectRoot, new ProcessRunner(), new ServiceRegistry([]), []));

        self::assertSame(1, $tester->execute(['--tag' => '1.0.0']));
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertFileDoesNotExist($projectRoot . '/ship/docker-compose.production.yml');
    }

    /**
     * The Laravel adapter requires APP_KEY (see DescribesRelease::requiredEnv()), so a release
     * without it fails like one without a database password.
     */
    public function test_a_setting_the_framework_requires_must_be_set_too(): void
    {
        $projectRoot = $this->project(new ShipConfig(phpVersion: '8.4', services: [], name: 'client'), "APP_URL=https://x.test\n");
        file_put_contents($projectRoot . '/artisan', "<?php\n");
        file_put_contents($projectRoot . '/composer.json', "{}\n");

        $tester = new CommandTester(new ReleaseCommand($projectRoot, new ProcessRunner(), new ServiceRegistry([]), []));

        self::assertSame(1, $tester->execute(['--tag' => '1.0.0']));
        self::assertStringContainsString('APP_KEY is required but not set in .env.production', $tester->getDisplay());
    }

    /**
     * ship's own generated build files don't make a release dirty; a changed source file does.
     */
    public function test_source_ignores_ships_generated_files_but_not_source_changes(): void
    {
        if ((new ProcessRunner())->runQuiet(['git', '--version']) === '') {
            self::markTestSkipped('git is not available.');
        }

        $projectRoot = $this->project(new ShipConfig(phpVersion: '8.4', services: [], name: 'client'), "APP_KEY=x\n");
        file_put_contents($projectRoot . '/app.php', "<?php\n");
        $git = static fn (string ...$args): string => (new ProcessRunner())->runQuiet(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.invalid', ...$args], $projectRoot);
        $git('init', '-q');
        $git('add', '-A');
        $git('commit', '-qm', 'Initial');

        mkdir($projectRoot . '/ship/prod', recursive: true);
        foreach (['ship/docker-compose.generated.yml', 'ship/docker-compose.production.yml', 'ship/prod/entrypoint.sh'] as $generated) {
            file_put_contents($projectRoot . '/' . $generated, "generated\n");
        }

        $command = new ReleaseCommand($projectRoot, new ProcessRunner());
        $source = new \ReflectionMethod($command, 'source');

        self::assertSame(['gitCommit' => $git('rev-parse', 'HEAD'), 'dirty' => false], $source->invoke($command));

        file_put_contents($projectRoot . '/app.php', "<?php // changed\n");

        self::assertTrue($source->invoke($command)['dirty']);
    }

    public function test_source_is_null_outside_a_git_repository(): void
    {
        $projectRoot = $this->project(new ShipConfig(phpVersion: '8.4', services: [], name: 'client'), "APP_KEY=x\n");
        $command = new ReleaseCommand($projectRoot, new ProcessRunner());

        self::assertSame(['gitCommit' => null, 'dirty' => null], (new \ReflectionMethod($command, 'source'))->invoke($command));
    }

    private function project(ShipConfig $config, string $envProduction, string $folder = 'project'): string
    {
        $projectRoot = sys_get_temp_dir() . '/ship-release-command-' . bin2hex(random_bytes(8)) . '/' . $folder;
        mkdir($projectRoot, recursive: true);
        $config->toFile($projectRoot . '/ship.json');
        file_put_contents($projectRoot . '/.env.production', $envProduction);
        $this->cleanup[] = dirname($projectRoot);

        return $projectRoot;
    }

    /** @var list<string> */
    private array $cleanup = [];

    /**
     * git writes its object files read-only, and Windows refuses to delete a read-only file, so
     * every file is made writable first (chmod() clears Windows' read-only attribute).
     */
    protected function tearDown(): void
    {
        foreach ($this->cleanup as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    @chmod($file->getPathname(), 0o666);
                }
            }
        }

        (new Filesystem())->remove($this->cleanup);
    }

    /**
     * @param array<string, string> $options
     */
    private function resolveTag(array $options, bool $interactive, ?BufferedOutput $output = null): ?string
    {
        $command = new ReleaseCommand(sys_get_temp_dir(), new ProcessRunner());
        $input = new ArrayInput($options, $command->getDefinition());
        $input->setInteractive($interactive);

        $method = new \ReflectionMethod($command, 'resolveTag');

        return $method->invoke($command, $input, $output ?? new BufferedOutput());
    }
}
