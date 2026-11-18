<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Command\Command;

/**
 * The docker calls `ship release` makes after the build: pulling and pinning the images it doesn't
 * build, and inspecting and saving the ones it does. Not final, so tests can stand in for Docker.
 */
class ReleaseDocker
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $cwd,
    ) {
    }

    /**
     * Streams `docker pull`'s progress. Returns whether it succeeded.
     */
    public function pull(string $ref): bool
    {
        return $this->runner->runInteractive(['docker', 'pull', $ref], $this->cwd) === Command::SUCCESS;
    }

    /**
     * The digest of $ref's repository from the local image's RepoDigests (`sha256:...`), which a
     * pull records. Null when there is none, e.g. for an image that was built locally.
     */
    public function repoDigest(string $ref): ?string
    {
        $result = $this->runner->runQuietWithResult(
            ['docker', 'image', 'inspect', '--format', '{{json .RepoDigests}}', $ref],
            $this->cwd,
        );

        if ($result['exitCode'] !== 0) {
            return null;
        }

        /** @var mixed $digests */
        $digests = json_decode($result['output'], associative: true);
        $repository = self::normalizedRepository($ref);

        foreach (is_array($digests) ? $digests : [] as $entry) {
            if (!is_string($entry) || !str_contains($entry, '@')) {
                continue;
            }

            [$name, $digest] = explode('@', $entry, 2);

            if (self::normalizedRepository($name) === $repository && str_starts_with($digest, 'sha256:')) {
                return $digest;
            }
        }

        return null;
    }

    /**
     * `docker image inspect`'s Id, e.g. `sha256:...`. Null when the image doesn't exist.
     */
    public function imageId(string $ref): ?string
    {
        return $this->inspect($ref, '{{.Id}}');
    }

    /**
     * `os/arch[/variant]`, e.g. `linux/amd64` or `linux/arm/v7`. Null when the image doesn't exist.
     */
    public function platform(string $ref): ?string
    {
        return $this->inspect($ref, '{{.Os}}/{{.Architecture}}{{if .Variant}}/{{.Variant}}{{end}}');
    }

    /**
     * Streams `docker save`'s output. Returns whether it succeeded.
     */
    public function save(string $ref, string $path): bool
    {
        return $this->runner->runInteractive(['docker', 'save', '-o', $path, $ref], $this->cwd) === Command::SUCCESS;
    }

    /**
     * The repository part of an image reference, in Docker Hub's canonical form, so `postgres`,
     * `library/postgres` and `docker.io/library/postgres` compare equal.
     */
    public static function normalizedRepository(string $ref): string
    {
        $name = explode('@', $ref, 2)[0];

        // A tag follows the last ":" unless that ":" is part of a registry host's port.
        $lastColon = strrpos($name, ':');
        if ($lastColon !== false && !str_contains(substr($name, $lastColon), '/')) {
            $name = substr($name, 0, $lastColon);
        }

        $parts = explode('/', $name);
        $hasRegistry = count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost');

        if (!$hasRegistry) {
            array_unshift($parts, 'docker.io');
        }

        if ($parts[0] === 'docker.io' && count($parts) === 2) {
            array_splice($parts, 1, 0, 'library');
        }

        return implode('/', $parts);
    }

    private function inspect(string $ref, string $format): ?string
    {
        $result = $this->runner->runQuietWithResult(['docker', 'image', 'inspect', '--format', $format, $ref], $this->cwd);

        return $result['exitCode'] === 0 && $result['output'] !== '' ? $result['output'] : null;
    }
}
