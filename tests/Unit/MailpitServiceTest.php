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
     * A bare `HOST:CONTAINER` mapping binds every interface, not just loopback, for a web UI
     * that only ever needs to reach the developer's own machine -- bound to 127.0.0.1 instead.
     * Mailpit is dev-only tooling entirely (see the production test below), so there's no
     * production case to leave unbound here.
     */
    public function test_the_web_ui_port_binds_loopback_only(): void
    {
        $fragment = (new MailpitService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['127.0.0.1:${MAILPIT_WEB_PORT:-8025}:8025'], $fragment['mailpit']['ports']);
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

    /**
     * Dev/test tooling, not infrastructure -- production must not get a Mailpit container, or
     * its MAIL_HOST would unconditionally override whatever real mail config .env.production sets
     * (environment: always wins over env_file:). An empty fragment is this class's own signal to
     * ComposeFileBuilder that it contributes nothing in production -- no container, no env vars
     * injected into "app" either.
     */
    public function test_it_contributes_nothing_in_production(): void
    {
        self::assertSame([], (new MailpitService())->composeFragment(ShipEnvironment::Production));
    }
}
