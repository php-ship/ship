#!/bin/sh
# Builds the frontend for the production image, in the "assets" stage of both Dockerfiles: installs
# with the project's package manager, runs its `build` script, and removes what only the build
# needed. A project without package.json has nothing to build.
#
#   build-assets.sh <npm|yarn|pnpm>
#
# The package manager is decided by ship (Ship\Node\PackageManager): package.json's packageManager
# field, else the project's lockfile, else npm. Yarn and pnpm run through Corepack, which uses the
# exact version packageManager names. An existing lockfile is installed exactly as it is (a lockfile
# that no longer matches package.json fails the build rather than being updated), and the build runs
# with the same tool, since Yarn's Plug'n'Play installs have no node_modules for npm to use.
#
# VITE_ENV holds .env.production's VITE_* settings as `export` lines (see Ship\Docker\ViteBuildEnv),
# which Vite bakes into the bundle.
set -eu

tool="${1:-npm}"

[ -f package.json ] || exit 0

# Node gives each address of a dual-stack host 250 ms by default ("happy eyeballs"), which a slow
# link from inside a build container regularly misses, failing a step that downloads (the package
# manager itself, packages, or Vite plugins such as Laravel's font loader). Raised where this Node
# supports it.
if node --network-family-autoselection-attempt-timeout=2500 -e 0 2>/dev/null; then
    export NODE_OPTIONS="--network-family-autoselection-attempt-timeout=2500 ${NODE_OPTIONS:-}"
fi

case "$tool" in
    npm)
        if [ -f package-lock.json ] || [ -f npm-shrinkwrap.json ]; then npm ci; else npm install; fi
        ;;
    yarn)
        if [ ! -f yarn.lock ]; then
            yarn install
        elif [ "$(yarn --version | cut -d. -f1)" = "1" ]; then
            yarn install --frozen-lockfile
        else
            yarn install --immutable
        fi
        ;;
    pnpm)
        if [ -f pnpm-lock.yaml ]; then pnpm install --frozen-lockfile; else pnpm install; fi
        ;;
    *)
        echo "build-assets.sh: unknown package manager \"$tool\" (npm, yarn or pnpm)" >&2
        exit 1
        ;;
esac

eval "${VITE_ENV:-}"
"$tool" run build

# Build dependencies only. Yarn's Plug'n'Play keeps its packages under .yarn/ instead.
rm -rf node_modules .yarn/cache .yarn/unplugged .yarn/install-state.gz
