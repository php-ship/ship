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
     * `**`-prefixed patterns are needed to match `.env`/`.env.*` at any depth, not just the build
     * context *root* -- a bare pattern would miss `dist/ship/<tag>/.env` (ship release's own copy
     * of .env.production), leaving it readable inside the very next image built in that project.
     * `/dist/ship` excludes ship release's own generated output outright.
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
     * auth.json (Composer's own credentials file, a private Packagist Pro/repo token most
     * commonly) and .npmrc (npm's equivalent) are real secret-bearing files `COPY . .` would bake
     * in exactly like .env, so both need excluding too.
     */
    public function test_it_excludes_composer_and_npm_credential_files(): void
    {
        DockerignoreGuard::ensure($this->projectRoot);

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertStringContainsString('**/auth.json', $written);
        self::assertStringContainsString('**/.npmrc', $written);
    }

    /**
     * A bare `/dist` would exclude a project's *entire* dist/ directory from the build context,
     * not just ship release's own dist/ship/ -- a project that keeps real build inputs under its
     * own dist/ (a separate build tool's output the image legitimately needs to COPY in, say)
     * would have them silently dropped from every image built, with no error. Narrowed to the
     * exact namespace ship release actually writes to.
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
     * This has to be safe to call every time `ship build`/`ship release` run, not just at `ship
     * init` time -- a project whose .dockerignore was hand-edited, reverted, or never existed
     * because `ship init` ran before this guard did still needs protection at the actual moment
     * `COPY . .` runs. Covered directly here since it's plain, Docker-independent file I/O;
     * ProductionBuildRunner's own call site is a single unconditional line at the top of build().
     */
    public function test_it_is_safe_to_call_repeatedly_on_the_same_project(): void
    {
        DockerignoreGuard::ensure($this->projectRoot);
        DockerignoreGuard::ensure($this->projectRoot);

        $written = (string) file_get_contents($this->projectRoot . '/.dockerignore');

        self::assertSame(1, substr_count($written, '**/.env.*'));
    }
}
