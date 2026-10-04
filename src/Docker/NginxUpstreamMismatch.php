<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;

/**
 * Renaming the app service (ship.json's serviceNames) only ever rewrites the *published*
 * ship/nginx/default.conf at `ship init` time (see InitCommand::publishStubs()) -- hand-editing
 * serviceNames afterward, without re-running `ship init`, leaves that file pointing at the *old*
 * name while ComposeFileBuilder renames the actual compose service to the new one on every `ship
 * up`/`ship build`/`ship release`, so nginx fails to resolve its upstream the moment the stale
 * name no longer matches anything in the stack. Shared by all three commands (not just
 * UpCommand, where this first lived) -- `ship build`/`ship release` build the exact same
 * prod-nginx image from this exact same stale file, so a rename can ship a webserver that can't
 * reach the app either, with nothing warning about it there.
 */
final class NginxUpstreamMismatch
{
    /**
     * Null when there's nothing to warn about -- no nginx stub published at all (an Octane
     * runtime was selected, or `ship init` was never run), the published file doesn't have the
     * expected `set $upstream_app` line, or the name it has already matches. Only ever warns,
     * never silently rewrites: a project may have hand-edited this file for other reasons (see
     * README's "Customizing the stack"), and overwriting it without being asked would be worse
     * than an outdated upstream name.
     */
    public static function warning(string $projectRoot, ShipConfig $config): ?string
    {
        $confPath = $projectRoot . '/ship/nginx/default.conf';

        if (!is_file($confPath)) {
            return null;
        }

        $conf = (string) file_get_contents($confPath);

        if (preg_match('/set \$upstream_app ([a-zA-Z0-9_.-]+):9000;/', $conf, $matches) !== 1) {
            return null;
        }

        $publishedAppName = $matches[1];
        $currentAppName = $config->serviceNames['app'] ?? 'app';

        if ($publishedAppName === $currentAppName) {
            return null;
        }

        return sprintf(
            '<comment>ship: ship/nginx/default.conf still points at "%s", but ship.json\'s '
                . 'serviceNames now renames the app service to "%s" -- nginx will fail to resolve '
                . 'its upstream. Run `ship init` again to republish it (it re-asks every prompt, so '
                . 'have your current selections ready to re-pick), or edit the `set $upstream_app` '
                . 'line in that file by hand.</comment>',
            $publishedAppName,
            $currentAppName,
        );
    }
}
