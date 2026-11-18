<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Node\PackageManager;
use Symfony\Component\Filesystem\Filesystem;

final class PackageManagerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-package-manager-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * @param list<string> $lockfiles
     */
    private function project(?string $packageManager, array $lockfiles = []): PackageManager
    {
        $package = ['name' => 'app', 'private' => true];
        if ($packageManager !== null) {
            $package['packageManager'] = $packageManager;
        }
        file_put_contents($this->projectRoot . '/package.json', json_encode($package));

        foreach ($lockfiles as $file) {
            file_put_contents($this->projectRoot . '/' . $file, "lock\n");
        }

        return PackageManager::inspect($this->projectRoot);
    }

    public function test_package_manager_decides(): void
    {
        $pm = $this->project('pnpm@12.21.0+sha512.abc', ['pnpm-lock.yaml']);

        self::assertSame('pnpm', $pm->tool());
        self::assertSame('pnpm', $pm->buildTool());
        self::assertNull($pm->problem());
    }

    public function test_without_package_manager_the_single_lockfile_decides(): void
    {
        self::assertSame('yarn', $this->project(null, ['yarn.lock'])->tool());
    }

    public function test_npm_shrinkwrap_counts_as_npm(): void
    {
        $pm = $this->project(null, ['npm-shrinkwrap.json']);

        self::assertSame('npm', $pm->tool());
        self::assertStringContainsString('npm-shrinkwrap.json', (string) $pm->refusal('pnpm'));
    }

    /**
     * Nothing has decided yet: any tool may create the first lockfile; the build uses npm.
     */
    public function test_nothing_decided_yet_allows_any_tool_and_builds_with_npm(): void
    {
        $pm = $this->project(null);

        self::assertNull($pm->tool());
        self::assertSame('npm', $pm->buildTool());
        foreach (PackageManager::TOOLS as $tool) {
            self::assertNull($pm->refusal($tool));
        }
    }

    public function test_several_lockfiles_without_package_manager_is_a_problem(): void
    {
        $pm = $this->project(null, ['package-lock.json', 'yarn.lock']);

        self::assertNull($pm->tool());
        self::assertNull($pm->buildTool());
        self::assertStringContainsString('package-lock.json, yarn.lock', (string) $pm->problem());
        self::assertStringContainsString('`ship package-manager <npm|yarn>`', (string) $pm->problem());
    }

    /**
     * What `ship init` leaves after choosing a new tool: the switch has to be finished.
     */
    public function test_a_declared_tool_without_its_lockfile_but_another_tools_is_an_unfinished_switch(): void
    {
        $pm = $this->project('pnpm@12.21.0', ['package-lock.json']);

        self::assertNull($pm->buildTool());
        self::assertStringContainsString("switching to pnpm hasn't been finished", (string) $pm->problem());
        self::assertStringContainsString('`ship package-manager pnpm`', (string) $pm->problem());
    }

    public function test_another_tools_leftover_lockfile_is_a_problem(): void
    {
        $pm = $this->project('yarn@4.18.1', ['yarn.lock', 'package-lock.json']);

        self::assertStringContainsString('package-lock.json is left over from npm', (string) $pm->problem());
        self::assertStringContainsString('`ship package-manager yarn`', (string) $pm->problem());
    }

    public function test_an_unsupported_package_manager_is_a_problem(): void
    {
        self::assertStringContainsString('"bun@1.2.0", which ship doesn\'t support', (string) $this->project('bun@1.2.0')->problem());
    }

    /**
     * Running another tool than the project's leaves lockfiles of two package managers.
     */
    public function test_running_another_tool_is_refused_with_the_command_to_use_and_how_to_switch(): void
    {
        $pm = $this->project(null, ['package-lock.json']);
        $refusal = (string) $pm->refusal('yarn');

        self::assertNull($pm->refusal('npm'));
        self::assertStringContainsString('This project uses npm (package-lock.json)', $refusal);
        self::assertStringContainsString('Use `ship npm …` instead', $refusal);
        self::assertStringContainsString('`ship package-manager yarn`', $refusal);
    }

    public function test_a_mixed_project_refuses_every_tool_with_the_problem(): void
    {
        $pm = $this->project(null, ['package-lock.json', 'pnpm-lock.yaml']);

        foreach (PackageManager::TOOLS as $tool) {
            self::assertStringContainsString('ship package-manager', (string) $pm->refusal($tool));
        }
    }

    /**
     * `ship npm init` creates package.json in the first place.
     */
    public function test_nothing_is_refused_without_a_package_json(): void
    {
        $pm = PackageManager::inspect($this->projectRoot);

        self::assertFalse($pm->hasPackageJson);
        self::assertNull($pm->refusal('pnpm'));
    }
}
