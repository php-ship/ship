<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Contracts\ShipEnvironment;

/**
 * A bare `HOST:CONTAINER` mapping binds every interface, exposing a dev port to the local
 * network. Development ports are bound to 127.0.0.1 instead; production mappings are left as
 * they are, since a published production port may need to be reachable from outside.
 */
final class DevPortBinding
{
    public static function bind(string $hostToContainer, ShipEnvironment $environment): string
    {
        return $environment->isDevelopment() ? "127.0.0.1:{$hostToContainer}" : $hostToContainer;
    }
}
