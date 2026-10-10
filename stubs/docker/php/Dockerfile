ARG PHP_VERSION=8.4
ARG OCTANE_RUNTIME=fpm
ARG NODE_VERSION=24

FROM node:${NODE_VERSION}-alpine AS node

FROM php:${PHP_VERSION}-fpm-alpine AS base
ARG OCTANE_RUNTIME

# pdo_pgsql/pdo_mysql/pcntl are installed regardless of which database or runtime is selected:
# cheap, and it avoids a build arg per extension. pcntl is needed by Octane and Reverb for signal
# handling.
RUN apk add --no-cache libpq-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql intl mbstring opcache pcntl

# Extra extensions from ship.json's phpExtensions, installed with
# mlocati/docker-php-extension-installer, which knows each extension's build dependencies. Skipped
# entirely when the list is empty. The installer is pinned to a release and checksum-verified;
# bump INSTALL_PHP_EXTENSIONS_VERSION and the checksum together.
ARG PHP_EXTENSIONS=""
ARG INSTALL_PHP_EXTENSIONS_VERSION=2.12.0
ARG INSTALL_PHP_EXTENSIONS_SHA256=3f49c71fa66c79b8b2b96bc0ce92885dafd7946f3b5a46f545b390d6f1617e2c
RUN if [ -n "$PHP_EXTENSIONS" ]; then \
        curl -sSLf "https://github.com/mlocati/docker-php-extension-installer/releases/download/${INSTALL_PHP_EXTENSIONS_VERSION}/install-php-extensions" -o /usr/local/bin/install-php-extensions \
        && echo "${INSTALL_PHP_EXTENSIONS_SHA256}  /usr/local/bin/install-php-extensions" | sha256sum -c - \
        && chmod +x /usr/local/bin/install-php-extensions \
        && install-php-extensions $PHP_EXTENSIONS; \
    fi

# Copied from the official node image at NODE_VERSION (musl-built, matching this Alpine base),
# since Alpine's own package only carries one Node version. npm is a JS CLI run through node, so
# its install tree comes along and the executable symlinks are recreated.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# phpredis is installed unconditionally: selecting Redis sets CACHE_STORE/SESSION_DRIVER=redis,
# and Laravel's default REDIS_CLIENT is "phpredis". Pinned to an exact PECL release; bump
# REDIS_EXTENSION_VERSION deliberately.
ARG REDIS_EXTENSION_VERSION=6.3.0
RUN apk add --no-cache --virtual .build-deps-redis $PHPIZE_DEPS \
    && pecl install redis-$REDIS_EXTENSION_VERSION \
    && docker-php-ext-enable redis \
    && apk del .build-deps-redis

# Swoole needs a compiled extension. RoadRunner is a Go binary pulled in via Composer and needs
# nothing here; FrankenPHP has its own Dockerfile. brotli-dev is required by Swoole's configure
# step. Pinned like REDIS_EXTENSION_VERSION.
ARG SWOOLE_EXTENSION_VERSION=6.2.3
RUN if [ "$OCTANE_RUNTIME" = "swoole" ]; then \
        apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers brotli-dev \
        && pecl install swoole-$SWOOLE_EXTENSION_VERSION \
        && docker-php-ext-enable swoole \
        && apk del .build-deps; \
    fi

WORKDIR /var/www/html

# ---- dev -------------------------------------------------------------
FROM base AS dev
# Pinned like REDIS_EXTENSION_VERSION.
ARG XDEBUG_EXTENSION_VERSION=3.5.3
RUN apk add --no-cache linux-headers $PHPIZE_DEPS \
    && pecl install xdebug-$XDEBUG_EXTENSION_VERSION \
    && docker-php-ext-enable xdebug
COPY ship/dev/php.ini /usr/local/etc/php/conf.d/99-ship-dev.ini
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
COPY ship/dev/entrypoint.sh /usr/local/bin/ship-entrypoint
RUN chmod +x /usr/local/bin/ship-entrypoint
# The project root is bind-mounted from the host in dev, so its files keep the host's UID/GID,
# which is rarely www-data's. The php-fpm pool therefore runs as root, so it can always write to
# storage/ and bootstrap/cache/ without touching host ownership. Dev only; prod bakes its files
# into the image at a known ownership.
#
# ship.json's hostUser (opt-in) builds this stage with the host's UID/GID instead, so files the
# container writes aren't root-owned on the host. HOST_UID stays 0 unless it's set. An existing
# user/group with the same id is reused, since only the numeric ids matter. php-fpm's master still
# starts as root (see CMD) and drops its workers to this user; the entrypoint drops everything
# else.
ARG HOST_UID=0
ARG HOST_GID=0
RUN apk add --no-cache su-exec
RUN if [ "$HOST_UID" = "0" ]; then         sed -i 's/^user = .*/user = root/; s/^group = .*/group = root/' /usr/local/etc/php-fpm.d/www.conf;     else         group=$(getent group "$HOST_GID" | cut -d: -f1);         [ -n "$group" ] || { addgroup -g "$HOST_GID" ship && group=ship; };         user=$(getent passwd "$HOST_UID" | cut -d: -f1);         [ -n "$user" ] || { adduser -D -u "$HOST_UID" -G "$group" ship && user=ship; };         mkdir -p /home/ship && chown "$HOST_UID:$HOST_GID" /home/ship;         sed -i "s/^user = .*/user = $user/; s/^group = .*/group = $group/" /usr/local/etc/php-fpm.d/www.conf;     fi
ENTRYPOINT ["/usr/local/bin/ship-entrypoint"]
CMD ["php-fpm", "--allow-to-run-as-root"]

# ---- builder -----------------------------------------------------------
FROM base AS builder
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---- assets --------------------------------------------------------------
# Runs `npm run build` so public/build exists before "prod" and "prod-nginx" copy the tree out.
# Guarded on package.json, since not every project has a frontend build. Built from "builder" so
# vendor/ exists in case a build step shells out to artisan.
#
# `npm ci` when a lockfile exists, `npm install` only when it doesn't, so lockfile drift still
# fails the build. node_modules is a build dependency and is removed afterwards.
FROM builder AS assets
RUN if [ -f package.json ]; then \
        if [ -f package-lock.json ]; then npm ci; else npm install; fi \
        && npm run build \
        && rm -rf node_modules; \
    fi

# ---- prod --------------------------------------------------------------
FROM base AS prod
COPY ship/prod/php.ini /usr/local/etc/php/conf.d/99-ship-prod.ini
COPY --from=assets /var/www/html /var/www/html
# su-exec lets the entrypoint drop an Octane server or extra process to www-data after its
# root-only steps (see EntrypointScriptBuilder).
RUN apk add --no-cache su-exec
# Generated by `ship build`/`ship release` (see EntrypointScriptBuilder), since its content
# depends on which FrameworkAdapter matches. Runs release commands at container boot, with the
# real env vars, then execs into CMD.
COPY ship/prod/entrypoint.sh /usr/local/bin/ship-entrypoint
RUN chmod +x /usr/local/bin/ship-entrypoint
ENTRYPOINT ["/usr/local/bin/ship-entrypoint"]
CMD ["php-fpm"]

# ---- dev-nginx / prod-nginx ----------------------------------------------
# In the same Dockerfile as the PHP stages so prod-nginx can COPY --from=assets without relying
# on cross-image build ordering.
#
# dev-nginx runs as root for the same reason the dev php-fpm pool does: the tree is bind-mounted
# (or synced by Mutagen) from the host, and nginx's unprivileged workers couldn't read it
# otherwise. prod-nginx keeps nginx's default user.
#
# Pinned to an exact nginx stable release; bump deliberately.
FROM nginx:1.30.5-alpine AS dev-nginx
COPY ship/nginx/default.conf /etc/nginx/conf.d/default.conf
RUN sed -i 's/^user  *nginx;/user root;/' /etc/nginx/nginx.conf

FROM nginx:1.30.5-alpine AS prod-nginx
COPY --from=assets /var/www/html/public /var/www/html/public
COPY ship/nginx/default.conf /etc/nginx/conf.d/default.conf

# FrankenPHP (octane-frankenphp) doesn't use this file: it has its own base image and
# Dockerfile.frankenphp, selected by OctaneFrankenPhpService. No Octane runtime uses the *-nginx
# stages, since each serves HTTP itself.
