<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Filesystem\Filesystem;

final class InitCommandDockerignoreTest extends TestCase
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
     * Regression coverage for a real secret leak found live: a bare `.env`/`.env.*` pattern only
     * ever matched at the build context *root*, not recursively, so `dist/ship/<tag>/.env` (ship
     * release's own copy of .env.production) was never excluded and landed readable inside the
     * very next image built in that project. `**`-prefixed patterns match at any depth, and `/dist`
     * excludes ship release's own generated output outright.
     */
    public function test_it_writes_recursive_env_patterns_and_excludes_dist(): void
    {
        $this->invokeEnsureDockerignoreExcludesEnv();

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertStringContainsString('**/.env', $written);
        self::assertStringContainsString('**/.env.*', $written);
        self::assertStringContainsString('!**/.env.example', $written);
        self::assertStringContainsString('/dist', $written);
    }

    public function test_it_does_not_duplicate_entries_already_present(): void
    {
        file_put_contents($this->projectRoot . '/.dockerignore', "**/.env\n**/.env.*\n!**/.env.example\n.git\nnode_modules\nvendor\n/dist\n");

        $this->invokeEnsureDockerignoreExcludesEnv();

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertSame(1, substr_count($written, '/dist'));
    }

    private function invokeEnsureDockerignoreExcludesEnv(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        (new \ReflectionMethod($command, 'ensureDockerignoreExcludesEnv'))->invoke($command);
    }
}
