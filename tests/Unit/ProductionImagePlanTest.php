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
     * Every `processes` entry copies "app"'s build config, so they share one tag and one group
     * instead of being built and exported separately.
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
     * "app" names a shared image whenever it's a member, regardless of service order.
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
     * Only an identical `build:` merges two services; FrankenPHP's own Dockerfile stays separate.
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
