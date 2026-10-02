#!/bin/sh
set -e

# Bind-mounting the project over /var/www/html means a project without a
# pre-existing host vendor/ (e.g. cloned without local PHP/Composer -- the
# exact case this package's "no local PHP needed" pitch invites) would
# otherwise build and start fine, then 500 on every request with a
# missing autoload.php. Only runs when vendor/ is actually missing, so it
# doesn't fight the bind mount -- or slow down every `ship up` -- once
# it's there.
#
# Also guarded on composer.json actually existing: in SHIP_MUTAGEN mode
# (see Ship\Sync\MutagenSync) this path is a named volume, not a bind
# mount, and starts out genuinely empty until the sync session -- created
# only after the container is confirmed running -- finishes its initial
# copy. Running composer install against nothing here doesn't just fail
# once; `composer.json` still being missing right after container start
# was `set -e` exiting the whole script, restarting the container into
# the exact same failure forever (see "restart: unless-stopped").
# Skipping straight to exec instead lets php-fpm come up against an empty
# directory (harmless -- it just 404s until Mutagen catches up) rather
# than crash-looping while it waits.
#
# SHIP_HOST_USER ("uid:gid", set from ship.json's hostUser -- see ShipConfig) runs it as the host's
# own user instead of root, so vendor/ isn't left root-owned on the host. HOME points at the home
# directory the image created for that user, since su-exec/setpriv keep root's, which it can't
# write. su-exec on the Alpine image; setpriv -- confirmed live to exec() its target directly, same
# as su-exec, not fork-and-wait -- on FrankenPHP's Debian one, which has no su-exec but already
# ships setpriv. setpriv wants --reuid/--regid as separate flags, not one "uid:gid" argument, hence
# the parameter expansion splitting it below.
if [ -n "$SHIP_HOST_USER" ]; then
    export HOME=/home/ship
fi

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    if [ -n "$SHIP_HOST_USER" ] && command -v su-exec >/dev/null 2>&1; then
        su-exec "$SHIP_HOST_USER" composer install --no-interaction
    elif [ -n "$SHIP_HOST_USER" ] && command -v setpriv >/dev/null 2>&1; then
        setpriv --reuid="${SHIP_HOST_USER%%:*}" --regid="${SHIP_HOST_USER##*:}" --clear-groups --no-new-privs composer install --no-interaction
    else
        composer install --no-interaction
    fi
fi

# php-fpm's master has to stay root to drop its own workers (the image's pool config does that);
# anything else that is its own long-lived program -- an Octane server -- would run as root,
# writing root-owned files into storage/, so it drops to the host user here.
if [ -n "$SHIP_HOST_USER" ] && [ "$1" != "php-fpm" ]; then
    if command -v su-exec >/dev/null 2>&1; then
        exec su-exec "$SHIP_HOST_USER" "$@"
    elif command -v setpriv >/dev/null 2>&1; then
        exec setpriv --reuid="${SHIP_HOST_USER%%:*}" --regid="${SHIP_HOST_USER##*:}" --clear-groups --no-new-privs "$@"
    fi
fi

exec "$@"
