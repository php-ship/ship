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
if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction
fi

exec "$@"
