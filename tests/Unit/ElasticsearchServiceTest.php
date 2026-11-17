<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\ElasticsearchService;

final class ElasticsearchServiceTest extends TestCase
{
    public function test_app_credentials_match_what_elasticsearch_is_provisioned_with(): void
    {
        $service = new ElasticsearchService();
        $esEnv = $service->composeFragment(ShipEnvironment::Development)['elasticsearch']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame('http://elasticsearch:9200', $appEnv['ELASTICSEARCH_HOST']);
        self::assertSame('elastic', $appEnv['ELASTICSEARCH_USERNAME']);
        self::assertSame($esEnv['ELASTIC_PASSWORD'], $appEnv['ELASTICSEARCH_PASSWORD']);
    }

    public function test_it_runs_a_single_node_with_security_on_and_plain_http(): void
    {
        $env = (new ElasticsearchService())->composeFragment(ShipEnvironment::Development)['elasticsearch']['environment'];

        self::assertSame('single-node', $env['discovery.type']);
        self::assertSame('true', $env['xpack.security.enabled']);
        self::assertSame('false', $env['xpack.security.http.ssl.enabled']);
    }

    public function test_the_password_is_required_in_production_not_just_overridable(): void
    {
        $env = (new ElasticsearchService())->composeFragment(ShipEnvironment::Production)['elasticsearch']['environment'];

        self::assertSame('${ELASTICSEARCH_PASSWORD:?set a real value in .env.production}', $env['ELASTIC_PASSWORD']);
    }

    /**
     * Uncapped, the JVM heap takes half the machine's memory.
     */
    public function test_the_heap_is_capped_in_development_only(): void
    {
        $service = new ElasticsearchService();

        self::assertSame('-Xms1g -Xmx1g', $service->composeFragment(ShipEnvironment::Development)['elasticsearch']['environment']['ES_JAVA_OPTS']);
        self::assertArrayNotHasKey('ES_JAVA_OPTS', $service->composeFragment(ShipEnvironment::Production)['elasticsearch']['environment']);
    }

    /**
     * No built-in Laravel driver exists, so nothing app-wide is switched over.
     */
    public function test_it_does_not_change_the_apps_scout_driver(): void
    {
        self::assertArrayNotHasKey('SCOUT_DRIVER', (new ElasticsearchService())->environmentVariables());
    }

    public function test_the_healthcheck_authenticates_with_compose_escaping(): void
    {
        $test = (new ElasticsearchService())->composeFragment(ShipEnvironment::Development)['elasticsearch']['healthcheck']['test'];

        self::assertSame('CMD-SHELL', $test[0]);
        self::assertStringContainsString('"elastic:$$ELASTIC_PASSWORD"', $test[1]);
        self::assertStringContainsString('wait_for_status=yellow', $test[1]);
    }

    public function test_a_named_instance_gets_its_own_service_volume_and_prefixed_variables(): void
    {
        $service = new ElasticsearchService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'logs');

        self::assertSame(['ship-elasticsearch-logs-data:/usr/share/elasticsearch/data'], $fragment['elasticsearch-logs']['volumes']);
        self::assertSame('${LOGS_ELASTICSEARCH_PASSWORD:-secret}', $fragment['elasticsearch-logs']['environment']['ELASTIC_PASSWORD']);
        self::assertSame(
            ['LOGS_ELASTICSEARCH_HOST' => 'http://elasticsearch-logs:9200', 'LOGS_ELASTICSEARCH_USERNAME' => 'elastic', 'LOGS_ELASTICSEARCH_PASSWORD' => '${LOGS_ELASTICSEARCH_PASSWORD:-secret}'],
            $service->environmentVariables('logs'),
        );
    }
}
