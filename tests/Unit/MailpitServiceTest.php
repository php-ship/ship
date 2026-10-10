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
     * The web UI only needs to reach the developer's machine, so it binds 127.0.0.1.
     */
    public function test_the_web_ui_port_binds_loopback_only(): void
    {
        $fragment = (new MailpitService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['127.0.0.1:${MAILPIT_WEB_PORT:-8025}:8025'], $fragment['mailpit']['ports']);
    }

    /**
     * There's no collision-free default host port for named instances, so they publish none.
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

    /**
     * In production Mailpit's MAIL_HOST would override the real mail config, so it contributes
     * no container and no env vars there.
     */
    public function test_it_contributes_nothing_in_production(): void
    {
        self::assertSame([], (new MailpitService())->composeFragment(ShipEnvironment::Production));
    }
}
