#!/bin/sh
set -e

# Install dependencies when vendor/ is missing, so a project cloned without local PHP/Composer
# still works. Guarded on composer.json existing: under SHIP_MUTAGEN (see Ship\Sync\MutagenSync)
# this path is a named volume that starts out empty, and a failed install would crash-loop the
# container.
#
# SHIP_HOST_USER ("uid:gid", from ship.json's hostUser) runs the install as the host's user, so
# vendor/ isn't root-owned on the host. HOME points at the home directory the image created for
# that user. su-exec on the Alpine image, setpriv (which takes uid and gid as separate flags) on
# FrankenPHP's Debian one.
if [ -n "$SHIP_HOST_USER" ]; then
    export HOME=/home/ship
fi

# php-fpm tolerates an empty /var/www/html, but an Octane server or Reverb needs composer.json
# and artisan to exist when it starts. Under SHIP_MUTAGEN the volume is empty until the first
# sync finishes, so wait for it (bounded, so a broken setup still fails).
if [ "$1" != "php-fpm" ]; then
    i=0
    while [ ! -f composer.json ] && [ "$i" -lt 120 ]; do
        sleep 1
        i=$((i + 1))
    done
fi

# SHIP_DEV_SKIP_INSTALL (set on Reverb always, and on "app" under SHIP_MUTAGEN -- see
# ComposeFileBuilder) means another process owns the install: Reverb shares "app"'s tree, and
# under Mutagen `ship up` installs once the sync has finished and composer.lock has arrived.
# Skip the install here and, for anything but php-fpm, wait for its result.
if [ -n "$SHIP_DEV_SKIP_INSTALL" ]; then
    if [ "$1" != "php-fpm" ]; then
        i=0
        while [ ! -f vendor/autoload.php ] && [ "$i" -lt 120 ]; do
            sleep 1
            i=$((i + 1))
        done
    fi
elif [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    if [ -n "$SHIP_HOST_USER" ] && command -v su-exec >/dev/null 2>&1; then
        su-exec "$SHIP_HOST_USER" composer install --no-interaction
    elif [ -n "$SHIP_HOST_USER" ] && command -v setpriv >/dev/null 2>&1; then
        setpriv --reuid="${SHIP_HOST_USER%%:*}" --regid="${SHIP_HOST_USER##*:}" --clear-groups --no-new-privs composer install --no-interaction
    else
        composer install --no-interaction
    fi
fi

# php-fpm's master stays root to drop its own workers; any other long-lived program (an Octane
# server, Reverb) drops to the host user here.
if [ -n "$SHIP_HOST_USER" ] && [ "$1" != "php-fpm" ]; then
    if command -v su-exec >/dev/null 2>&1; then
        exec su-exec "$SHIP_HOST_USER" "$@"
    elif command -v setpriv >/dev/null 2>&1; then
        exec setpriv --reuid="${SHIP_HOST_USER%%:*}" --regid="${SHIP_HOST_USER##*:}" --clear-groups --no-new-privs "$@"
    fi
fi

exec "$@"
