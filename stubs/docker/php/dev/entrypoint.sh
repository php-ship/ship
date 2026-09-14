#!/bin/sh
set -e

# Bind-mounting the project over /var/www/html means a project without a
# pre-existing host vendor/ (e.g. cloned without local PHP/Composer -- the
# exact case this package's "no local PHP needed" pitch invites) would
# otherwise build and start fine, then 500 on every request with a
# missing autoload.php. Only runs when vendor/ is actually missing, so it
# doesn't fight the bind mount -- or slow down every `ship up` -- once
# it's there.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction
fi

exec "$@"
