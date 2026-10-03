<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Contracts\ShipEnvironment;

/**
 * A bare `HOST:CONTAINER` port mapping binds every interface (0.0.0.0), not just loopback -- the
 * host is reachable from anything else on the same network, or the open internet on a cloud dev
 * box with no firewall, for a port (Vite, Mailpit's web UI, the app itself, Reverb, Silo's
 * console) that only ever needs to reach the developer's own machine. Development only: a
 * production port often does need to be reachable from outside -- directly when
 * ShipConfig::$publishPorts allows it, or through a reverse proxy sharing the host's own
 * interfaces -- a concern development never had.
 */
final class DevPortBinding
{
    public static function bind(string $hostToContainer, ShipEnvironment $environment): string
    {
        return $environment->isDevelopment() ? "127.0.0.1:{$hostToContainer}" : $hostToContainer;
    }
}
