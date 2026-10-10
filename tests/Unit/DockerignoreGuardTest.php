<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\DockerignoreGuard;
use Symfony\Component\Filesystem\Filesystem;

final class DockerignoreGuardTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-dockerignore-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * `**`-prefixed patterns match at any depth; a bare `.env` would miss
     * `dist/ship/<tag>/.env`.
     */
    public function test_it_writes_recursive_env_patterns_and_excludes_dist_ship(): void
    {
        DockerignoreGuard::ensure($this->projectRoot);

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertStringContainsString('**/.env', $written);
        self::assertStringContainsString('**/.env.*', $written);
        self::assertStringContainsString('!**/.env.example', $written);
        self::assertStringContainsString('/dist/ship', $written);
    }

    /**
     * auth.json and .npmrc carry Composer/npm credentials.
     */
    public function test_it_excludes_composer_and_npm_credential_files(): void
    {
        DockerignoreGuard::ensure($this->projectRoot);

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertStringContainsString('**/auth.json', $written);
        self::assertStringContainsString('**/.npmrc', $written);
    }

    /**
     * Only `ship release`'s own output is excluded; a project's other files under dist/ may be
     * build inputs.
     */
    public function test_it_excludes_only_dist_ship_not_the_whole_dist_directory(): void
    {
        DockerignoreGuard::ensure($this->projectRoot);

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertStringNotContainsString("/dist\n", $written);
    }

    public function test_it_does_not_duplicate_entries_already_present(): void
    {
        file_put_contents(
            $this->projectRoot . '/.dockerignore',
            "**/.env\n**/.env.*\n!**/.env.example\n**/auth.json\n**/.npmrc\n.git\nnode_modules\nvendor\n/dist/ship\n",
        );

        DockerignoreGuard::ensure($this->projectRoot);

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertSame(1, substr_count($written, '/dist/ship'));
    }

    /**
     * `ship build`/`ship release` call this on every run, so it must be idempotent.
     */
    public function test_it_is_safe_to_call_repeatedly_on_the_same_project(): void
    {
        DockerignoreGuard::ensure($this->projectRoot);
        DockerignoreGuard::ensure($this->projectRoot);

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertSame(1, substr_count($written, '**/.env.*'));
    }
}
