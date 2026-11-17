<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * A single-node Elasticsearch with security on: every request authenticates as the built-in
 * `elastic` user, whose password is a default in development and required in production. HTTP
 * stays plain inside the compose network; TLS belongs to whatever fronts it, as for every other
 * service here.
 *
 * The app gets ELASTICSEARCH_HOST/USERNAME/PASSWORD. Laravel has no built-in Elasticsearch driver,
 * so nothing app-wide (SCOUT_DRIVER) is switched over: the app wires these into the client package
 * it picks.
 */
final class ElasticsearchService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'elasticsearch';
    }

    public function label(): string
    {
        return 'Elasticsearch';
    }

    public function group(): string
    {
        return 'search';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            $name => [
                'image' => 'elasticsearch:9.5.5',
                'environment' => [
                    // One node: no cluster formation, and Elasticsearch's production bootstrap
                    // checks (vm.max_map_count, ...) only warn instead of refusing to start.
                    'discovery.type' => 'single-node',
                    'xpack.security.enabled' => 'true',
                    // Setting these explicitly also skips the image's security auto-configuration,
                    // which would otherwise generate certificates and a random password at first boot.
                    'xpack.security.http.ssl.enabled' => 'false',
                    'xpack.security.transport.ssl.enabled' => 'false',
                    // A default in dev, required in production (see RequiredEnv).
                    'ELASTIC_PASSWORD' => RequiredEnv::expr("{$prefix}ELASTICSEARCH_PASSWORD", 'secret', $environment),
                    // Without a cap the JVM heap is sized to half the machine's memory, which a
                    // development laptop shouldn't give up. Production keeps the automatic sizing.
                    ...($environment->isDevelopment() ? ['ES_JAVA_OPTS' => '-Xms1g -Xmx1g'] : []),
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/usr/share/elasticsearch/data"],
                'healthcheck' => [
                    // $$ keeps Compose from interpolating the container's own variable.
                    'test' => [
                        'CMD-SHELL',
                        'curl -fsS -u "elastic:$$ELASTIC_PASSWORD" '
                            . '"http://localhost:9200/_cluster/health?wait_for_status=yellow&timeout=5s" >/dev/null',
                    ],
                    'interval' => '10s',
                    'timeout' => '10s',
                    'retries' => 12,
                    // The JVM takes a while to start; failures during this window don't count.
                    'start_period' => '30s',
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            "{$prefix}ELASTICSEARCH_HOST" => "http://{$name}:9200",
            "{$prefix}ELASTICSEARCH_USERNAME" => 'elastic',
            // The same expression composeFragment() provisions Elasticsearch with.
            "{$prefix}ELASTICSEARCH_PASSWORD" => "\${{$prefix}ELASTICSEARCH_PASSWORD:-secret}",
        ];
    }

    public function removes(): array
    {
        return [];
    }
}
