ARG PHP_VERSION=8.4
ARG OCTANE_RUNTIME=fpm
ARG NODE_VERSION=24

FROM node:${NODE_VERSION}-alpine AS node

FROM php:${PHP_VERSION}-fpm-alpine AS base
ARG OCTANE_RUNTIME

# pdo_pgsql/pdo_mysql/pcntl are installed unconditionally regardless of
# which database or runtime is selected: cheap enough to always install,
# and avoids plumbing a second build arg through ComposeFileBuilder just
# for extension selection. pcntl specifically is needed by Laravel Octane
# (graceful reload/shutdown signal handling) and Laravel Reverb (its
# server references the SIGINT/SIGTERM/SIGTSTP constants pcntl defines).
RUN apk add --no-cache libpq-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql intl mbstring opcache pcntl

# Copied from the official node image at the pinned NODE_VERSION -- musl-built (matches this
# Alpine base), not installed via `apk add nodejs npm`, since Alpine's own package repo only ever
# carries whatever single Node version happens to ship with that Alpine release, independent of
# NODE_VERSION. npm has no binary of its own; it's a JS CLI run through node, so its install
# tree (node_modules/npm) has to come along too, with the executable symlinks recreated here.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# phpredis is installed unconditionally (not gated behind a "redis
# selected" build arg) because RedisService::environmentVariables() sets
# CACHE_STORE=redis / SESSION_DRIVER=redis on "app" whenever Redis is
# picked in `ship init` -- and Laravel's default REDIS_CLIENT is
# "phpredis", which needs this extension, not just a reachable redis
# container. Cheap enough to always install rather than plumb a second
# build arg through ComposeFileBuilder just for this.
RUN apk add --no-cache --virtual .build-deps-redis $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps-redis

# Swoole needs a compiled PHP extension; RoadRunner is a standalone Go
# binary pulled in via Composer (spiral/roadrunner-bin) so it needs
# nothing here; FrankenPHP is handled separately below since it replaces
# the base image rather than layering onto it. brotli-dev is there
# specifically for Swoole's own configure step -- it requests Brotli
# support (--enable-brotli=yes) by default and aborts the whole build
# with "Package 'libbrotlienc' not found" without it, even though
# nothing else here uses Brotli.
RUN if [ "$OCTANE_RUNTIME" = "swoole" ]; then \
        apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers brotli-dev \
        && pecl install swoole \
        && docker-php-ext-enable swoole \
        && apk del .build-deps; \
    fi

WORKDIR /var/www/html

# ---- dev -------------------------------------------------------------
FROM base AS dev
RUN apk add --no-cache linux-headers $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug
COPY ship/dev/php.ini /usr/local/etc/php/conf.d/99-ship-dev.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY ship/dev/entrypoint.sh /usr/local/bin/ship-entrypoint
RUN chmod +x /usr/local/bin/ship-entrypoint
# The project root is bind-mounted straight from the host in dev (see baseServices()), so
# storage/, bootstrap/cache/, etc. keep whatever host UID/GID created them -- on a real Linux
# host (any native Linux dev machine, and this project's own CI) that's almost never uid 82
# (www-data), which is who php-fpm's pool workers run as by default. The result: every request
# fails outright ("tempnam(): file created in the system's temporary directory", from Laravel's
# atomic config/view cache writes) the moment it needs to write anything under the project root.
# Chowning the bind mount to match isn't an option either -- that changes the *host's* files,
# surprising whoever owns them. Running the pool as root instead sidesteps host permissions
# entirely without ever touching them; dev already trades hardening for convenience (xdebug is
# always on, and the entrypoint above already runs `composer install` as root when it falls
# back), so this doesn't lower the bar dev was already set at. Never done in prod -- see that
# stage below -- where the files are baked into the image at a known ownership instead.
RUN sed -i 's/^user = .*/user = root/; s/^group = .*/group = root/' /usr/local/etc/php-fpm.d/www.conf
ENTRYPOINT ["/usr/local/bin/ship-entrypoint"]
CMD ["php-fpm", "--allow-to-run-as-root"]

# ---- builder -----------------------------------------------------------
FROM base AS builder
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---- assets --------------------------------------------------------------
# Runs `npm run build` (Vite's production bundle) so public/build exists
# before "prod" and "prod-nginx" copy the tree out. Guarded on
# package.json existing, since not every consuming project has a frontend
# build step at all -- this must never be a hard requirement. Built from
# "builder" (not "base") specifically so vendor/ and the composer
# autoloader already exist, in case any npm build step shells out to
# artisan (Ziggy route generation, an SSR manifest, ...).
FROM builder AS assets
RUN if [ -f package.json ]; then \
        (npm ci || npm install) && npm run build; \
    fi

# ---- prod --------------------------------------------------------------
FROM base AS prod
COPY ship/prod/php.ini /usr/local/etc/php/conf.d/99-ship-prod.ini
COPY --from=assets /var/www/html /var/www/html
# Generated by `ship up --prod` (see UpCommand + EntrypointScriptBuilder),
# not a static stub -- its content depends on which FrameworkAdapter
# matches the project. Runs release commands (e.g. Laravel's
# config:cache) at container *boot*, using whatever real env vars the
# orchestrator injects then, and finally execs into CMD.
COPY ship/prod/entrypoint.sh /usr/local/bin/ship-entrypoint
RUN chmod +x /usr/local/bin/ship-entrypoint
ENTRYPOINT ["/usr/local/bin/ship-entrypoint"]
CMD ["php-fpm"]

# ---- dev-nginx / prod-nginx ----------------------------------------------
# Independent FROM chains (not built on "base") in the same Dockerfile as
# the PHP stages specifically so prod-nginx can COPY --from=assets --
# cross-Dockerfile COPY --from would need the referenced image to already
# exist at build time with no guaranteed build order, which plain
# `docker compose build` doesn't provide. Same-Dockerfile multi-stage
# COPY --from has none of that problem. dev-nginx doesn't need any of
# this -- nginx and php-fpm both bind-mount the same source tree in dev,
# see ComposeFileBuilder::baseServices() -- but lives here too so both
# environments build through one Dockerfile instead of splitting nginx
# into its own file for no reason other than "it's a different image".
# Runs as root, not nginx:alpine's own default "nginx" user, for the same reason "app"'s dev
# php-fpm pool does (see that RUN sed line's own docblock): dev bind-mounts (or, in SHIP_MUTAGEN
# mode, syncs into a named volume) the project root from the host, so file ownership here isn't
# baked in at a known value the way it is in "prod" -- confirmed live, not hypothetical: Mutagen's
# own agent injection runs via `docker exec` as this container's default user, and nginx's own
# unprivileged worker processes couldn't read what that user had just written, 403ing every
# request with "is forbidden (13: Permission denied)". Prod keeps nginx's own default -- its files
# are baked into the image at build time by the COPY below, at a known, consistent ownership.
FROM nginx:alpine AS dev-nginx
COPY ship/nginx/default.conf /etc/nginx/conf.d/default.conf
RUN sed -i 's/^user  *nginx;/user root;/' /etc/nginx/nginx.conf

FROM nginx:alpine AS prod-nginx
COPY --from=assets /var/www/html/public /var/www/html/public
COPY ship/nginx/default.conf /etc/nginx/conf.d/default.conf

# FrankenPHP (octane-frankenphp) does not use this file at all — it has its
# own base image (Debian + Caddy, not php:*-fpm-alpine) and its own
# Dockerfile.frankenphp in this same directory, selected by
# OctaneFrankenPhpService overriding "app.build.dockerfile". See that
# file's header comment for why FrankenPHP can't share this one. It also
# never needs the *-nginx stages above: FrankenPHP is a full HTTP server
# and serves public/ itself once Octane hands it the request, so
# "webserver" is never created when it's selected (see
# OctaneFrankenPhpService::removes()) -- the same reason no Octane
# runtime ever coexists with nginx.
