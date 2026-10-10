<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Runtime\ProcessRunner;
use Ship\Support\ShipVersion;

/**
 * release.json's content. Metadata only; no `.env.production` value passes through this class.
 */
final class ReleaseManifest
{
    /**
     * @param list<array{tag: string, canonicalService: string, members: list<string>}> $images
     * @return array<string, mixed>
     */
    public static function build(string $tag, string $projectRoot, ProcessRunner $runner, array $images): array
    {
        return [
            'tag' => $tag,
            'gitCommit' => self::gitCommit($projectRoot, $runner),
            'shipVersion' => ShipVersion::current(),
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'images' => array_map(
                static fn (array $image): array => [
                    'tag' => $image['tag'],
                    'services' => $image['members'],
                    'file' => "images/{$image['canonicalService']}.tar",
                ],
                $images,
            ),
        ];
    }

    /**
     * Null when the project isn't a git repository or `git` isn't on PATH; this is metadata, not
     * a requirement.
     */
    private static function gitCommit(string $projectRoot, ProcessRunner $runner): ?string
    {
        $sha = $runner->runQuiet(['git', 'rev-parse', 'HEAD'], $projectRoot);

        return $sha === '' ? null : $sha;
    }
}
