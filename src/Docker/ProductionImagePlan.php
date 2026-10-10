<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * Decides which generated compose services are project-owned images to build and tag, and groups
 * the ones that are the same image under one tag.
 *
 * "app", "reverb" and every `processes` entry usually share one Dockerfile, target and args.
 * Services whose `build:` matches share one tag, one build and one exported tar.
 */
final class ProductionImagePlan
{
    /**
     * Services with no `build:` are returned untouched; the others get an `image:`.
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
            // "app" names the shared image when it's in the group; otherwise the first member,
            // so the name is stable.
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
