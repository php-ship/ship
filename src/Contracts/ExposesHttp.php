<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a ServiceDefinition whose container receives HTTP traffic of its own, besides
 * the site that "webserver" or "app" serves (e.g. Reverb's WebSocket server). Ship\Docker\HttpEntryPoints
 * collects these: an external network (ShipConfig::$externalNetwork) is attached to each of them, and
 * `ship release` lists them in release.json's `http`.
 */
interface ExposesHttp
{
    /**
     * Keyed by default compose service name, the container port and its role (`site`, `websocket`,
     * ...).
     *
     * @return array<string, array{port: int, role: string}>
     */
    public function httpEntryPoints(): array;
}
