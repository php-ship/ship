<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;

final class ServiceRegistry
{
    /** @var array<string, ServiceDefinition> */
    private array $services = [];

    /**
     * @param list<ServiceDefinition> $builtIns
     */
    public function __construct(array $builtIns = [])
    {
        foreach ($builtIns as $service) {
            $this->register($service);
        }
    }

    public function register(ServiceDefinition $service): void
    {
        $this->services[$service->key()] = $service;
    }

    public function get(string $key): ServiceDefinition
    {
        return $this->services[$key]
            ?? throw new \OutOfBoundsException("No registered service with key \"{$key}\".");
    }

    /**
     * @return list<ServiceDefinition>
     */
    public function all(): array
    {
        return array_values($this->services);
    }

    /**
     * @return list<ServiceDefinition>
     */
    public function inGroup(string $group): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (ServiceDefinition $s): bool => $s->group() === $group,
        ));
    }

    /**
     * The default, built-in service set; extensions from ship.json's array register on top, at boot.
     *
     * @return list<ServiceDefinition>
     */
    public static function defaults(): array
    {
        return [
            new PostgresService(),
            new MySqlService(),
            new RedisService(),
            new SeaweedFsService(),
            new GarageService(),
            new OctaneSwooleService(),
            new OctaneRoadRunnerService(),
            new OctaneFrankenPhpService(),
            new MeilisearchService(),
            new MailpitService(),
            new DuskService(),
            new NodeService(),
            new ReverbService(),
        ];
    }
}
