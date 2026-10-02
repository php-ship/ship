<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * Decides which generated compose services are project-owned images `ship build`/`ship release` must
 * build and tag, and groups the ones that are really the *same* image under one tag.
 *
 * "app", "reverb", and every `ship.json` `processes` entry build from the exact same Dockerfile,
 * target and args whenever a project selects more than one of them (see
 * ComposeFileBuilder::addProcessServices(), which literally copies "app"'s own `build:`) -- building,
 * tagging and `docker save`-ing each separately would triple a release's size for content that's
 * byte-for-byte identical. Grouped by the service's own `build:` array instead: any two services
 * whose `build:` matches end up sharing one tag, one build, and one exported tar.
 */
final class ProductionImagePlan
{
    /**
     * Services with no `build:` (the registry-pulled infrastructure) are returned completely
     * untouched -- this only ever adds `image:` to, and groups, the ones that have one.
     *
     * @param array<string, array<string, mixed>> $services
     * @return array{
     *     services: array<string, array<string, mixed>>,
     *     images: list<array{tag: string, canonicalService: string, members: list<string>}>,
     * }
     */
    public static function plan(array $services, string $projectName, string $appServiceName, string $tagSuffix): array
    {
        $groups = [];

        foreach ($services as $name => $service) {
            if (!isset($service['build'])) {
                continue;
            }

            $signature = self::signature($service['build']);
            $groups[$signature][] = $name;
        }

        $images = [];

        foreach ($groups as $members) {
            // "app" wins when it's part of the group, since it's the service a reader of the
            // release would expect the shared image to be named after -- otherwise the first
            // member in a stable (insertion) order, so the same project always picks the same name.
            $canonical = in_array($appServiceName, $members, true) ? $appServiceName : $members[0];
            $tag = sprintf('%s-%s:%s', $projectName, $canonical, $tagSuffix);

            foreach ($members as $member) {
                $services[$member]['image'] = $tag;
            }

            $images[] = ['tag' => $tag, 'canonicalService' => $canonical, 'members' => $members];
        }

        return ['services' => $services, 'images' => $images];
    }

    /**
     * @param array<string, mixed> $build
     */
    private static function signature(array $build): string
    {
        self::ksortRecursive($build);

        return json_encode($build, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}
