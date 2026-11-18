<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Docker\ReleaseDocker;

final class ReleaseDockerTest extends TestCase
{
    /**
     * RepoDigests spells Docker Hub images the short way ("postgres@sha256:..."), so a digest is
     * matched to its image by repository, in one canonical form.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function references(): iterable
    {
        yield 'official image' => ['postgres:18-alpine', 'docker.io/library/postgres'];
        yield 'bare name' => ['postgres', 'docker.io/library/postgres'];
        yield 'full official name' => ['docker.io/library/postgres:18', 'docker.io/library/postgres'];
        yield 'user image' => ['getmeili/meilisearch:v1.53', 'docker.io/getmeili/meilisearch'];
        yield 'other registry' => ['mcr.microsoft.com/mssql/server:2025-CU11-ubuntu-24.04', 'mcr.microsoft.com/mssql/server'];
        yield 'registry with a port' => ['localhost:5000/team/app:1.0', 'localhost:5000/team/app'];
        yield 'registry port, no tag' => ['registry.test:5000/app', 'registry.test:5000/app'];
        yield 'digest reference' => ['redis@sha256:' . str_repeat('a', 64), 'docker.io/library/redis'];
    }

    #[DataProvider('references')]
    public function test_repositories_are_normalized(string $reference, string $expected): void
    {
        self::assertSame($expected, ReleaseDocker::normalizedRepository($reference));
    }
}
