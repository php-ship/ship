<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\ProductionImagePlan;

final class ProductionImagePlanTest extends TestCase
{
    public function test_a_service_with_no_build_is_left_completely_untouched(): void
    {
        $services = ['mysql' => ['image' => 'mysql:9.7']];

        $plan = ProductionImagePlan::plan($services, 'acme', 'app', 'local');

        self::assertSame($services, $plan['services']);
        self::assertSame([], $plan['images']);
    }

    public function test_a_build_having_service_gets_tagged_and_its_own_image_group(): void
    {
        $services = ['app' => ['build' => ['context' => '.', 'dockerfile' => 'ship/Dockerfile', 'target' => 'prod']]];

        $plan = ProductionImagePlan::plan($services, 'acme', 'app', '1.2.0');

        self::assertSame('acme-app:1.2.0', $plan['services']['app']['image']);
        self::assertSame(
            [['tag' => 'acme-app:1.2.0', 'canonicalService' => 'app', 'members' => ['app']]],
            $plan['images'],
        );
    }

    /**
     * ComposeFileBuilder::addProcessServices() literally copies "app"'s own build: array for every
     * ship.json `processes` entry -- building, tagging and exporting each one separately would
     * triple a release's size for content that's byte-for-byte identical. Services whose `build:`
     * matches share exactly one tag and one group instead.
     */
    public function test_services_with_an_identical_build_share_one_tag_and_group(): void
    {
        $build = ['context' => '.', 'dockerfile' => 'ship/Dockerfile', 'target' => 'prod'];
        $services = [
            'app' => ['build' => $build],
            'horizon' => ['build' => $build],
            'scheduler' => ['build' => $build],
        ];

        $plan = ProductionImagePlan::plan($services, 'acme', 'app', 'local');

        self::assertSame('acme-app:local', $plan['services']['app']['image']);
        self::assertSame('acme-app:local', $plan['services']['horizon']['image']);
        self::assertSame('acme-app:local', $plan['services']['scheduler']['image']);
        self::assertCount(1, $plan['images']);
        self::assertSame(['app', 'horizon', 'scheduler'], $plan['images'][0]['members']);
    }

    /**
     * "app" is the name a reader of the release would expect a shared image to be named after --
     * picked over any other member, regardless of which order the services happen to appear in.
     */
    public function test_app_is_preferred_as_the_groups_canonical_name_when_present(): void
    {
        $build = ['context' => '.', 'dockerfile' => 'ship/Dockerfile', 'target' => 'prod'];
        $services = ['horizon' => ['build' => $build], 'app' => ['build' => $build]];

        $plan = ProductionImagePlan::plan($services, 'acme', 'app', 'local');

        self::assertSame('app', $plan['images'][0]['canonicalService']);
        self::assertSame('acme-app:local', $plan['images'][0]['tag']);
    }

    /**
     * A genuinely different build (FrankenPHP's own Dockerfile, say) must never collapse into the
     * same group as "app" just because they share no members in common -- only an identical
     * `build:` array ever merges two services.
     */
    public function test_different_builds_stay_in_separate_groups(): void
    {
        $services = [
            'app' => ['build' => ['context' => '.', 'dockerfile' => 'ship/Dockerfile', 'target' => 'prod']],
            'webserver' => ['build' => ['context' => '.', 'dockerfile' => 'ship/Dockerfile', 'target' => 'prod-nginx']],
        ];

        $plan = ProductionImagePlan::plan($services, 'acme', 'app', 'local');

        self::assertCount(2, $plan['images']);
        self::assertNotSame($plan['services']['app']['image'], $plan['services']['webserver']['image']);
    }
}
