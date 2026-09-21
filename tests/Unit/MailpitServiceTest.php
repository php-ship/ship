<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\MailpitService;

final class MailpitServiceTest extends TestCase
{
    public function test_the_default_instance_publishes_its_web_ui(): void
    {
        $fragment = (new MailpitService())->composeFragment(ShipEnvironment::Development);

        self::assertNotSame([], $fragment['mailpit']['ports']);
    }

    /**
     * There's no single sane default host port for an arbitrary number of named instances without
     * risking a collision -- a named instance's web UI stays reachable inside the Docker network
     * only, unless a project adds its own docker-compose.override.yml entry.
     */
    public function test_a_named_instance_does_not_publish_a_web_ui_port(): void
    {
        $fragment = (new MailpitService())->composeFragment(ShipEnvironment::Development, 'marketing');

        self::assertSame([], $fragment['mailpit-marketing']['ports']);
    }

    public function test_a_named_instance_gets_its_own_host_and_env_prefix(): void
    {
        $service = new MailpitService();
        $appEnv = $service->environmentVariables('marketing');

        self::assertSame('mailpit-marketing', $appEnv['MARKETING_MAIL_HOST']);
    }
}
