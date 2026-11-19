<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\InitCommand;
use Ship\Console\Commands\PackageManagerCommand;
use Ship\Console\Commands\ProxyCommand;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Node\PackageManager;
use Ship\Node\PackageManagerVersions;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * `ship npm`/`yarn`/`pnpm`'s guard, `ship package-manager`, `ship init`'s prompt, the registry
 * lookup and the production build argument. Every case here stops before anything would run in a
 * container.
 */
final class PackageManagerCommandsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-pm-commands-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    private function packageJson(string $json = "{\n  \"name\": \"app\",\n  \"private\": true,\n  \"devDependencies\": {}\n}\n"): void
    {
        file_put_contents($this->projectRoot . '/package.json', $json);
    }

    /**
     * @param \Closure(string): ?string|null $fetch
     */
    private function versions(?\Closure $fetch = null): PackageManagerVersions
    {
        return new PackageManagerVersions($fetch ?? static fn (string $url): ?string => match (true) {
            str_ends_with($url, '/pnpm') => '{"dist-tags":{"latest":"12.21.0"}}',
            str_ends_with($url, '/yarn') => '{"dist-tags":{"latest":"1.22.22"}}',
            str_ends_with($url, '/@yarnpkg%2fcli-dist') => '{"dist-tags":{"latest":"4.18.1"}}',
            str_ends_with($url, '/dist/index.json') => '[{"version":"v26.13.1","npm":"11.20.0"},{"version":"v24.22.3","npm":"11.19.1"},{"version":"v24.22.2","npm":"11.19.0"}]',
            default => null,
        });
    }

    public function test_ship_yarn_refuses_in_an_npm_project_and_points_at_the_switch(): void
    {
        $this->packageJson();
        touch($this->projectRoot . '/package-lock.json');

        $tester = new CommandTester(new ProxyCommand('yarn', 'app', 'yarn', $this->projectRoot, new ProcessRunner(), packageManager: 'yarn'));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('This project uses npm (package-lock.json)', $tester->getDisplay());
        self::assertStringContainsString('ship package-manager yarn', $tester->getDisplay());
    }

    public function test_package_manager_rejects_an_unsupported_tool_and_a_pinned_npm(): void
    {
        $this->packageJson();
        $tester = new CommandTester(new PackageManagerCommand($this->projectRoot, new ProcessRunner()));

        self::assertSame(1, $tester->execute(['tool' => 'bun']));
        self::assertStringContainsString("isn't a package manager ship supports", $tester->getDisplay());

        self::assertSame(1, $tester->execute(['tool' => 'npm@10']));
        self::assertStringContainsString('follows nodeVersion in ship.json', $tester->getDisplay());
    }

    public function test_package_manager_needs_a_package_json(): void
    {
        $tester = new CommandTester(new PackageManagerCommand($this->projectRoot, new ProcessRunner()));

        self::assertSame(1, $tester->execute(['tool' => 'pnpm']));
        self::assertStringContainsString("there's no package.json", $tester->getDisplay());
    }

    /**
     * Switching shows what it removes and changes nothing when the user says no.
     */
    public function test_switching_lists_what_it_removes_and_asks_first(): void
    {
        $this->packageJson();
        touch($this->projectRoot . '/package-lock.json');
        mkdir($this->projectRoot . '/node_modules');

        $tester = new CommandTester(new PackageManagerCommand($this->projectRoot, new ProcessRunner()));
        $tester->setInputs(['n']);
        $exitCode = $tester->execute(['tool' => 'pnpm']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Switching the project to pnpm:', $tester->getDisplay());
        self::assertStringContainsString('remove   package-lock.json', $tester->getDisplay());
        self::assertStringContainsString('remove   node_modules/', $tester->getDisplay());
        self::assertStringContainsString('Nothing changed.', $tester->getDisplay());
        self::assertFileExists($this->projectRoot . '/package-lock.json');
        self::assertDirectoryExists($this->projectRoot . '/node_modules');
    }

    public function test_switching_without_a_terminal_needs_yes(): void
    {
        $this->packageJson();
        touch($this->projectRoot . '/yarn.lock');

        $tester = new CommandTester(new PackageManagerCommand($this->projectRoot, new ProcessRunner()));

        self::assertSame(1, $tester->execute(['tool' => 'npm'], ['interactive' => false]));
        self::assertStringContainsString('Run it again with --yes', $tester->getDisplay());
        self::assertFileExists($this->projectRoot . '/yarn.lock');
    }

    /**
     * Finishing a switch `ship init` recorded keeps the version it recorded; npm records the npm
     * the container's Node comes with.
     */
    public function test_the_container_script_keeps_a_recorded_version_and_removes_only_the_listed_files(): void
    {
        $command = new PackageManagerCommand($this->projectRoot, new ProcessRunner());
        $script = new \ReflectionMethod($command, 'script');

        self::assertSame(
            "set -e && rm -rf 'package-lock.json' 'node_modules' && corepack use 'pnpm@12.21.0'",
            $script->invoke($command, 'pnpm', '12.21.0', ['package-lock.json', 'node_modules/']),
        );
        self::assertSame(
            'set -e && npm pkg set "packageManager=npm@$(npm --version)" && npm install',
            $script->invoke($command, 'npm', null, []),
        );
    }

    public function test_the_registry_lookup_follows_the_projects_yarn_line(): void
    {
        $versions = $this->versions();
        $this->packageJson();

        self::assertSame('pnpm@12.21.0', $versions->latest('pnpm', $this->projectRoot));
        self::assertSame('yarn@4.18.1', $versions->latest('yarn', $this->projectRoot));

        file_put_contents($this->projectRoot . '/yarn.lock', "# THIS IS AN AUTOGENERATED FILE. DO NOT EDIT THIS FILE DIRECTLY.\n# yarn lockfile v1\n");
        self::assertSame('yarn@1.22.22', $versions->latest('yarn', $this->projectRoot));

        self::assertNull($this->versions(static fn (string $url): ?string => null)->latest('pnpm', $this->projectRoot));
    }

    /**
     * Recording packageManager keeps the file's indentation, key order and empty objects.
     */
    public function test_recording_keeps_package_json_as_it_was_otherwise(): void
    {
        $this->packageJson("{\n  \"name\": \"app\",\n  \"scripts\": {\n    \"build\": \"vite build\"\n  },\n  \"devDependencies\": {}\n}\n");

        PackageManagerVersions::record($this->projectRoot, 'pnpm@12.21.0');

        self::assertSame(
            "{\n  \"name\": \"app\",\n  \"scripts\": {\n    \"build\": \"vite build\"\n  },\n  \"devDependencies\": {},\n  \"packageManager\": \"pnpm@12.21.0\"\n}\n",
            file_get_contents($this->projectRoot . '/package.json'),
        );
    }

    /**
     * Pressing Enter keeps what the project uses: a Yarn project without packageManager gets it
     * recorded at its Yarn line's current version.
     */
    public function test_init_records_the_package_manager_a_yarn_project_already_uses(): void
    {
        $this->packageJson();
        file_put_contents($this->projectRoot . '/yarn.lock', "__metadata:\n  version: 8\n");

        $tester = new CommandTester(new InitCommand($this->projectRoot, new ServiceRegistry([]), $this->versions()));
        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString('"packageManager": "yarn@4.18.1"', (string) file_get_contents($this->projectRoot . '/package.json'));
        self::assertStringNotContainsString('ship package-manager', $tester->getDisplay());
    }

    /**
     * npm is recorded at the version the project's Node comes with (the default Node, 24, here).
     */
    public function test_init_records_the_npm_the_projects_node_comes_with(): void
    {
        $this->packageJson();
        touch($this->projectRoot . '/package-lock.json');

        $tester = new CommandTester(new InitCommand($this->projectRoot, new ServiceRegistry([]), $this->versions()));
        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString('"packageManager": "npm@11.19.1"', (string) file_get_contents($this->projectRoot . '/package.json'));
        self::assertStringNotContainsString('ship package-manager', $tester->getDisplay());
    }

    /**
     * Choosing npm for a Yarn project records it, so `ship up` reminds and the build refuses until
     * `ship package-manager npm` finishes the switch.
     */
    public function test_init_choosing_npm_for_a_yarn_project_records_an_unfinished_switch(): void
    {
        $this->packageJson();
        file_put_contents($this->projectRoot . '/yarn.lock', "__metadata:\n  version: 8\n");

        $tester = new CommandTester(new InitCommand($this->projectRoot, new ServiceRegistry([]), $this->versions()));
        // PHP and Node versions (defaults), then npm instead of Yarn. With no services there's no
        // question about additional instances.
        $tester->setInputs(['', '', 'npm']);
        $tester->execute([]);

        $pm = PackageManager::inspect($this->projectRoot);

        self::assertSame('npm', $pm->declared);
        self::assertNull($pm->buildTool());
        self::assertStringContainsString('`ship package-manager npm`', (string) $pm->problem());
        // SymfonyStyle wraps the note, so compare without the line breaks.
        self::assertStringContainsString('`ship package-manager npm`', (string) preg_replace('/\s*\n\s*!?\s*/', ' ', $tester->getDisplay()));
    }

    public function test_the_npm_lookup_takes_the_newest_release_of_the_node_version(): void
    {
        self::assertSame('npm@11.19.1', $this->versions()->latest('npm', $this->projectRoot, '24'));
        self::assertSame('npm@11.20.0', $this->versions()->latest('npm', $this->projectRoot, '26'));
        self::assertSame('npm@11.19.0', $this->versions()->latest('npm', $this->projectRoot, '24.22.2'));
        self::assertNull($this->versions()->latest('npm', $this->projectRoot, '99'));
    }

    /**
     * Only the production build installs with a chosen tool; development installs with `ship <tool>`.
     */
    public function test_only_the_production_app_build_gets_the_package_manager(): void
    {
        $builder = new ComposeFileBuilder(new ServiceRegistry([]));
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $production = Yaml::parse($builder->build($config, ShipEnvironment::Production));
        $development = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('${SHIP_PACKAGE_MANAGER:-npm}', $production['services']['app']['build']['args']['PACKAGE_MANAGER']);
        self::assertArrayNotHasKey('PACKAGE_MANAGER', $development['services']['app']['build']['args']);
    }
}
