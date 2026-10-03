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

# A real bug found via an independent re-audit: php-fpm tolerates an empty /var/www/html (see
# above), but anything else that's "its own long-lived program" -- an Octane server, or Reverb, a
# *separate* container sharing this exact same SHIP_MUTAGEN-synced volume (see
# ComposeFileBuilder::alignReverbWithApp()) -- does not. `php artisan ...` needs composer.json/
# artisan to exist the moment it starts, and the named volume starts out genuinely empty until
# Mutagen's first sync pass finishes, so exec'ing straight into it crashed immediately -- and kept
# crashing every restart-policy retry until the sync eventually caught up, confirmed live. Bounded
# (not infinite) so a genuinely broken setup still fails eventually instead of hanging forever.
if [ "$1" != "php-fpm" ]; then
    i=0
    while [ ! -f composer.json ] && [ "$i" -lt 120 ]; do
        sleep 1
        i=$((i + 1))
    done
fi

# SHIP_DEV_SKIP_INSTALL (set on Reverb unconditionally, and on "app" itself when SHIP_MUTAGEN is
# active -- see ComposeFileBuilder::alignReverbWithApp()/baseServices()) -- a second real bug
# found the same way: Reverb and "app" share this exact same volume, so both this entrypoint
# (running as Reverb) and app's own container independently satisfying the
# composer.json-present/vendor-missing condition below would run `composer install` *twice*,
# concurrently, into the same vendor/ -- confirmed from reading the code, not observed live. Only
# one real installer is ever needed; everything else sharing the volume just waits for its result
# instead of racing to produce it a second time.
#
# A third, found via a fourth independent audit: with an Octane runtime selected, "app" itself is
# *not* php-fpm, so it's exactly as exposed to this race as Reverb is -- the install that mattered
# was `ship up`'s own, via MutagenSync::installComposerDependencies() (which only ever runs once
# the sync is fully "Watching", guaranteeing composer.lock has actually arrived), not whichever one
# this entrypoint happened to attempt first against a partially-synced tree. Gated on
# `$1 != "php-fpm"`, the same as the wait loop above, so setting this unconditionally whenever
# SHIP_MUTAGEN is active (regardless of which runtime "app" itself ends up running) is still a
# complete no-op for plain php-fpm, which never reaches here at all.
if [ -n "$SHIP_DEV_SKIP_INSTALL" ] && [ "$1" != "php-fpm" ]; then
    i=0
    while [ ! -f vendor/autoload.php ] && [ "$i" -lt 120 ]; do
        sleep 1
        i=$((i + 1))
    done
elif [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
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
