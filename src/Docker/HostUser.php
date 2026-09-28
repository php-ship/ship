<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * The invoking user's UID/GID, for ship.json's hostUser (see ShipConfig::$hostUser).
 */
final class HostUser
{
    /**
     * Null wherever there's nothing meaningful to match: no POSIX functions (native Windows PHP),
     * or already root -- a root host user is exactly what dev already runs as.
     *
     * @return array{uid: int, gid: int}|null
     */
    public static function detect(): ?array
    {
        if (!function_exists('posix_getuid') || !function_exists('posix_getgid')) {
            return null;
        }

        $uid = posix_getuid();
        $gid = posix_getgid();

        return $uid === 0 ? null : ['uid' => $uid, 'gid' => $gid];
    }
}
