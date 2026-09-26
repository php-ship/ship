# ship

[![CI](https://github.com/php-ship/ship/actions/workflows/ci.yml/badge.svg)](https://github.com/php-ship/ship/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/php-ship/ship)](https://packagist.org/packages/php-ship/ship)
[![Packagist Downloads](https://img.shields.io/packagist/dt/php-ship/ship)](https://packagist.org/packages/php-ship/ship)
[![License](https://img.shields.io/packagist/l/php-ship/ship)](LICENSE.md)

A framework-agnostic, cross-platform Docker development environment for
PHP — inspired by Laravel Sail, but usable outside Laravel and without
requiring Windows users to put their project inside WSL.

**Status: production-ready.** `init`/`up`/`down`/`exec`/`shell`/`logs`/
`db`/`composer`/`npm`/`artisan`/`console` all work end-to-end, verified
against a real Docker daemon in both dev and production mode — including
a real production HTTP request, a real database, and a real crashed-
container recovery. CI passes on every push, across Ubuntu/macOS/Windows
x PHP 8.2/8.3/8.4 plus a separate job that runs the same dev/production
verification against a real Docker daemon on every commit. See
[`docs/roadmap.md`](docs/roadmap.md) for the small number of known,
low-severity gaps that remain.

## Contents

- [Why not just use Sail?](#why-not-just-use-sail)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Commands](#commands)
- [Services](#services)
- [Configuration (`ship.json`)](#configuration-shipjson)
  - [Adding a service later](#adding-a-service-later)
  - [Multiple instances of a service](#multiple-instances-of-a-service)
- [Production build](#production-build)
- [HTTPS / TLS](#https--tls)
- [Backing up data volumes](#backing-up-data-volumes)
- [Frontend dev server (Vite HMR)](#frontend-dev-server-vite-hmr)
- [Faster file sync on Windows/macOS (Mutagen)](#faster-file-sync-on-windowsmacos-mutagen)
- [Running more than one project at once](#running-more-than-one-project-at-once)
- [Customizing the stack](#customizing-the-stack)
- [Extending ship](#extending-ship)
- [Architecture](#architecture)
- [Contributing](#contributing)
- [License](#license)

## Why not just use Sail?

Sail's CLI is a bash script, so on Windows it only runs inside WSL or Git
Bash, which is why Sail docs tell you to keep your project inside the WSL
filesystem. `ship`'s CLI is a PHP executable instead (via Symfony Console +
Process), so the exact same `ship up` / `ship exec` commands run natively on
Windows, macOS, and Linux. Docker Desktop on Windows still uses WSL2 under
the hood to run Linux containers — that part is unavoidable — but your
*project files* don't have to live there.

`ship` also isn't Laravel-specific. The core package knows nothing about
artisan, Blade, or Eloquent — it just builds and runs a Docker Compose
stack from a `ServiceDefinition` list. Laravel and Symfony support (`ship
artisan`, `ship console`) are each a
[`FrameworkAdapter`](src/Contracts/FrameworkAdapter.php) implementation,
built on the same public extension point any other framework could use —
two built-in adapters, not one framework with everything else bolted on.

A few other differences beyond the WSL requirement:

- **One command deploys to production, not just development.** Sail is a
  dev tool; going to production is left entirely up to you. `ship up
  --prod` builds a real production image (no bind mount, assets built,
  `.env` never baked in, framework release/optimize commands run at boot)
  from the exact same `ship.json` — see [Production build](#production-build).
  On a server, starting the whole stack really is `docker compose up -d`.
- **A real database client shell, without a published port.** `ship db`
  opens `psql`/`mysql` inside whichever database container is selected,
  reading its own credentials from its own environment — no port needs to
  be exposed to the host at all, and nothing needs to know the password.
- **Extend the stack without forking or hand-maintaining a Dockerfile.** A
  `docker-compose.override.yml` in your project layers custom services or
  Dockerfile overrides on top of what `ship` generates, using Compose's
  own standard override-file convention — see
  [Customizing the stack](#customizing-the-stack). New infrastructure
  (databases, caches, storage backends) and new frameworks are each a
  small class implementing one of two public interfaces, loadable from a
  separate Composer package via `ship.json`'s `extensions` array — see
  [Extending ship](#extending-ship) — not a fork of this one.
- **MySQL and Postgres, Swoole and RoadRunner and FrankenPHP, Meilisearch,
  object storage, Reverb, browser testing** — `ship init` picks one
  option per group interactively; nothing needed is hardcoded to a single
  choice the way Sail's own service list is.

## Requirements

- PHP 8.2+ and Composer, to install and run `ship` itself.
- Docker Desktop (or another Docker Engine + Compose v2 setup) — this is
  what actually runs your project; `ship` just drives `docker compose`.
- A `composer.json` in your project root. Nothing else — no framework
  required, no PHP extensions, no local database or Node install.

## Quick start

```bash
composer require --dev php-ship/ship
vendor/bin/ship init      # pick your services interactively
vendor/bin/ship up        # build and start
vendor/bin/ship artisan migrate   # if artisan is detected at the project root
vendor/bin/ship composer require some/package
vendor/bin/ship shell     # drop into the app container
vendor/bin/ship down
```

`ship init` writes `ship.json` and publishes a `ship/` directory (Dockerfile,
nginx/php config) into your project root — both are meant to be committed.
`ship up --prod` builds and starts the production-mode stack instead — see
[Production build](#production-build) below for what changes and why.

## Commands

| Command | What it does |
|---|---|
| `ship init` | Interactive picker for services (database, cache, runtime, ...); writes `ship.json` and publishes the `ship/` directory. Re-run it after upgrading `ship` to pick up stub changes — see `docs/roadmap.md`'s "Known gaps". |
| `ship up [--prod]` | Regenerates `ship/docker-compose.generated.yml` from `ship.json` and runs `docker compose up --build -d`. `--prod` builds the production image instead of dev. |
| `ship down [--volumes]` | `docker compose down`. `--volumes` also deletes named volumes (database/storage data — use with care). |
| `ship exec <service> <cmd...>` | Runs an arbitrary command inside a running service container. The generic escape hatch every shortcut below wraps. |
| `ship shell [service]` | Opens an interactive shell (`sh`) in a service; defaults to `app`. |
| `ship logs [service] [-f]` | Tails logs for one service, or all services if none given. `-f`/`--follow` streams live. |
| `ship db [instance]` | Opens a database's interactive client shell (`psql`/`mysql`), reading credentials from that container's own environment. Omit `instance` for the default database; name one of `additionalServices`' database entries to open that instance instead. Fails clearly if no database is selected, or the named instance doesn't exist. |
| `ship composer <args...>` | Proxies to `composer` inside the `app` container. |
| `ship npm <args...>` | Proxies to `npm` inside the `app` container (e.g. `ship npm run dev`, `ship npm install`). |
| `ship artisan <args...>` | Proxies to `php artisan` inside `app` — only registered when Laravel's `artisan` is detected at the project root. |
| `ship console <args...>` | Proxies to `php bin/console` inside `app` — only registered when Symfony's `bin/console` is detected at the project root. |

Every proxied command forwards its arguments exactly as typed (including
flags like `-m` in `ship artisan make:model Post -m`), so `ship` doesn't
need to know about every artisan or console subcommand.

## Services

`ship init` groups services (database, cache, runtime, storage, search,
mail, testing, frontend, broadcasting) and lets you pick one per group.
The table below shows what each built-in `ServiceDefinition` gets you
automatically, and what's still on the consuming app to add. "Wired"
means the container runs and the right env vars are set on `app`; it does
*not* mean the PHP package or extension that reads those env vars is
necessarily installed — that's a separate, app-level decision this tool
doesn't make for you.

| Service | Wired automatically | Still needed in the app |
|---|---|---|
| Postgres | `DB_*` env vars, `pdo_pgsql` extension | Nothing — works out of the box |
| MySQL | `DB_*` env vars, `pdo_mysql` extension | Nothing — works out of the box |
| Redis | `CACHE_STORE`/`SESSION_DRIVER`/`REDIS_*`, `redis` PHP extension | Nothing — works out of the box |
| Mailpit | `MAIL_HOST`/`MAIL_PORT` | Nothing (fresh Laravel already defaults `MAIL_MAILER=smtp`) |
| Meilisearch | `SCOUT_DRIVER`/`MEILISEARCH_*` | `composer require laravel/scout meilisearch/meilisearch-php` |
| Garage / SeaweedFS / RustFS / Silo | `AWS_*` env vars (S3-compatible) | `composer require league/flysystem-aws-s3-v3`, and set `FILESYSTEM_DISK=s3` yourself. Garage auto-provisions its own default bucket; RustFS/SeaweedFS/Silo don't — create it yourself once (Silo has a web console for this at `${SILO_CONSOLE_PORT:-9001}`; RustFS's own console doesn't currently work, see that class's own docblock) |
| Octane (Swoole/RoadRunner/FrankenPHP) | `swoole` PHP extension (Swoole only), `OCTANE_SERVER` | `composer require laravel/octane`, then `php artisan octane:install` inside the container once (downloads the RoadRunner binary if that's the one picked) |
| Dusk | Selenium container, `DUSK_DRIVER_URL` | `composer require --dev laravel/dusk` |
| Node.js / npm | Installed unconditionally in the base image, at the version `ship init`'s "Node.js version" prompt sets (`ship.json`'s `node` field, default 24); `ship npm run dev` works regardless of whether this group is selected | See [Frontend dev server](#frontend-dev-server-vite-hmr) below for HMR |
| Reverb | Its own `reverb` container + published port, `BROADCAST_CONNECTION=reverb`, server-side `REVERB_HOST=reverb`/`REVERB_PORT`/`REVERB_SCHEME` (Docker DNS — `app` talking to `reverb`), browser-side `VITE_REVERB_HOST=localhost`/`VITE_REVERB_PORT`/`VITE_REVERB_SCHEME` (what Echo in the browser actually connects to) | `composer require laravel/reverb`, then `php artisan install:broadcasting` inside the container once (generates `REVERB_APP_ID`/`KEY`/`SECRET` into `.env` — real per-app credentials, not something `ship` provisions). `ship init` warns at selection time if the package isn't installed yet, since the `reverb` container won't start without it. |

Everything in the right column is one-time setup per project, not a `ship`
limitation — `ship`'s job stops at "the infrastructure is reachable with the
right env vars," not "every possible Composer package is pre-installed."

## Configuration (`ship.json`)

`ship init` writes this file; you normally don't hand-edit it, but it's a
plain, readable JSON document:

```json
{
    "php": "8.4",
    "node": "24",
    "services": {
        "database": "pgsql",
        "cache": "redis",
        "frontend": "node",
        "broadcasting": "reverb"
    },
    "additionalServices": [
        {"group": "database", "service": "mysql", "name": "analytics"}
    ],
    "extensions": []
}
```

- `php` — the PHP version built into the image (`ARG PHP_VERSION` in
  `ship/Dockerfile`).
- `node` — the Node.js major version built into the image (`ARG
  NODE_VERSION`), independent of whether the `frontend` group's Node
  service is selected — Node installs unconditionally either way (see
  the Services table below).
- `services` — one selected service key per group; a group with no entry
  means "none selected".
- `additionalServices` — extra, named instances beyond the one in
  `services` (a second database, a second Redis, ...). See
  [Multiple instances of a service](#multiple-instances-of-a-service)
  below.
- `extensions` — fully-qualified class names of third-party
  `ServiceDefinition`/`FrameworkAdapter` implementations to load. See
  [Extending ship](#extending-ship).
- `serviceNames` / `externalNetwork` — rename any compose service (app,
  webserver, database, cache, ...) and/or attach the app to a
  pre-existing Docker network. Omitted here since almost no project
  needs them — see
  [Custom service names and an external network](#custom-service-names-and-an-external-network).

Host-side port collisions (running more than one `ship` project, or
another tool already using a default port) are handled with environment
variables at `ship up` time, not by editing `ship.json` — see
[Running more than one project at once](#running-more-than-one-project-at-once).

### Adding a service later

`ship init` asks about every group each time it runs, so re-running it to
add one service means re-picking all of them. For most services, that's
not actually necessary: add the entry directly to `ship.json`'s
`services` object (e.g. `"broadcasting": "reverb"`) and run `ship up`
again — it reads `ship.json` fresh every time, regardless of how it got
there. The one exception is Garage, which needs a config file `ship
init` publishes into `ship/garage/`; picking it that way is the reliable
option. Re-running `ship init` is always safe too, it just means
answering every prompt again and republishing `ship/`'s stub files.

Upgrading the `php-ship/ship` package itself is different: `ship up`
doesn't republish stub files on its own — only `ship init` does — so
it warns instead, comparing the version recorded at the last `ship
init` against what's currently installed, and telling you to re-run
`ship init` if they differ.

### Multiple instances of a service

`ship.json`'s `services` object holds exactly one selection per group —
enough for a project with one database, one cache, one of everything.
Some projects genuinely need more: a Postgres primary plus a MySQL
connection into a legacy system, a second Redis kept separate from the
default cache/session store, an S3-compatible bucket used only for
backups. `additionalServices` is for that — each entry adds one more
instance of a service, under a name of your choice:

```json
"additionalServices": [
    {"group": "database", "service": "mysql", "name": "analytics"}
]
```

`ship init` offers to add these interactively too, right after the main
picker ("Add a named additional service instance?"), and validates the
name you type there. Editing `ship.json` by hand instead, that `name`
must be lowercase letters, digits, and underscores only, starting with
a letter (`analytics`, `queue_2` — not `Analytics`, `my analytics`, or
`2nd-db`) — it becomes two things at once:

- The env vars this instance's connection details use — instead of the
  default instance's `DB_HOST`/`DB_CONNECTION`/etc., you get
  `ANALYTICS_DB_HOST`/`ANALYTICS_DB_CONNECTION`/etc. Wire it into your
  own app config the same way you would for any second connection — for
  Laravel, a second entry in `config/database.php`'s `connections`
  array reading those `ANALYTICS_*` variables.
- The compose service name — `mysql-analytics` here, distinct from the
  plain `mysql` a default MySQL selection would produce, so named
  volumes (`ship-mysql-analytics-data`) and `ship db analytics` (open
  that instance's shell specifically, instead of the default database's)
  both resolve unambiguously.

Not every group supports this — only ones where a second instance
actually means something: `database`, `cache`, `storage`, `search`, and
`mail`. A second application runtime, frontend toolchain, testing
driver, or broadcasting server isn't a coherent idea, so those stay
single-select only.

### Custom service names and an external network

By default every compose service uses a fixed name — the PHP container
is `app`, its nginx container is `webserver`, a selected database is its
engine's own key (`mysql`, `pgsql`, ...), and so on. Fine for one project
on its own, but not once several `ship`-managed projects need to sit on
the same Docker network: Compose gives every container a network alias
matching its service name, so two projects each running, say, an `app`
or a `mysql` collide the instant they join that network together.

Not prompted by `ship init` — hand-edit `ship.json` when you actually
need it:

```json
{
    "serviceNames": {
        "app": "client-app",
        "webserver": "client-web",
        "mysql": "client-db"
    },
    "externalNetwork": "shared_infra"
}
```

- `serviceNames` — maps a service's default compose name to a custom
  one. Every `ship` command that targets a renamed service (`ship
  shell`, `ship exec`, `ship db`, `ship composer`/`ship npm`/`ship
  artisan`, Mutagen sync) follows the rename automatically, as does the
  env var carrying that service's own hostname (`DB_HOST`, `REDIS_HOST`,
  ...) — only the hostname changes; a driver identifier that happens to
  read the same as the old name (`DB_CONNECTION=mysql`) is left alone.
  Rename as many or as few as you need; anything not listed keeps its
  default name. Doesn't apply to `additionalServices` entries — those
  already get their own distinct compose name via their own `name`.
- `externalNetwork` — the name of a Docker network created outside
  `ship` (by another compose project, e.g. one running your shared
  MySQL/Redis/etc.), attached to the app service in addition to `ship`'s
  own internal network. This is what actually lets the app reach that
  shared infrastructure — point your own `.env` (`DB_HOST`, `REDIS_HOST`,
  ...) at whatever hostname that other project's containers are reachable
  as, the same way you would without `ship` involved at all.

A project that doesn't select any of `ship`'s own database/cache/storage
services (an empty, or mostly empty, `services` object) is exactly the
shape `externalNetwork` is for: infrastructure lives in another compose
project entirely, and this project's app container just needs a way in.
`serviceNames` is for the separate, narrower case of a service `ship`
*does* provision locally (your own project's database, say) needing a
name that won't collide with an unrelated container already using its
default alias on a network you share.

## Production build

`ship up --prod` builds a materially different image, not just the same one
with a flag flipped:

- **No bind mount.** `dev` bind-mounts `.:/var/www/html` so edits are live
  immediately; `prod` deliberately doesn't, since a real deploy target
  shouldn't depend on the host filesystem it happened to build on. Code
  gets `COPY`'d into the image at build time instead, via the `builder` →
  `assets` → `prod` stage chain in `ship/Dockerfile`.
- **`.env` never enters the image, but is still loaded at container start if
  present on disk.** `ship init` publishes a `.dockerignore` excluding
  `.env`/`.env.*`/`.git`/`node_modules`/`vendor` from the build context —
  this matters more than it might look, since Docker image layers are
  additive, so even a `RUN rm .env` in a later stage wouldn't actually
  remove it from the image's history; the only correct fix is keeping it
  out of the build context in the first place. `app`, `webserver`, and
  `reverb` all declare an optional `env_file: .env` (Compose's
  `required: false`, so a project with none still starts fine), so a real
  `.env` placed directly on the deploy target's filesystem — never
  committed, never baked into an image — is exactly how secrets like
  `APP_KEY` are meant to reach the container. Ship's own explicit
  `environment:` values (the `DB_*`/`REDIS_*`/etc. each selected service
  wires) still win over anything conflicting in that file.
- **Frontend assets get built.** A `npm run build` step (guarded on
  `package.json` existing) runs in the `assets` stage, so `public/build`
  exists before `prod`/`prod-nginx` copy the tree out. There is no Vite
  *dev* server in production — see [Frontend dev server](#frontend-dev-server-vite-hmr)
  below for that, which only applies in development.
- **Each framework's own optimize command runs at container *boot*, not
  build time.** Laravel gets `php artisan optimize`; Symfony gets `php
  bin/console cache:clear`. This has to happen at boot rather than during
  the image build because these commands bake real environment values
  into compiled files — and per the `.env` point above, those real values
  only exist once the orchestrator injects them at container start, not
  during the build. `Ship\Contracts\FrameworkAdapter::releaseCommands()`
  is the extension point for this — a third-party adapter for another
  framework returns its own list of boot-time commands the same way.
- **nginx can now actually serve static assets.** `webserver` builds from
  the same `ship/Dockerfile` as `app` (a `prod-nginx` target that copies
  from the `assets` stage), so it has access to `public/`'s built CSS/JS
  instead of only being able to proxy PHP requests to `app`.

Deploying is then meant to be: build the image (`ship up --prod`, or your
CI pipeline running the equivalent `docker compose ... build`), push it
wherever it runs, and start it there with real environment variables
injected by the orchestrator — a single `docker compose up -d` against
the same generated compose file, once the image exists and the env vars
are in place.

## HTTPS / TLS

`webserver` only listens on plain HTTP (port 80) — `ship` doesn't
terminate TLS itself. This is a deliberate scope boundary, not an
oversight: TLS termination is meant to happen in front of `ship`, the
same way it would in front of any other containerized app —

- a load balancer or reverse proxy you already run (an ALB/NLB, Cloudflare,
  a Caddy/Traefik/another nginx instance) forwarding plain HTTP to
  `webserver`'s published port, or
- a sidecar you add yourself via
  [`docker-compose.override.yml`](#customizing-the-stack) — for example a
  `caddy` or `traefik` service in front of `webserver`, with your certs
  mounted in.

Serving real traffic over the bare `${APP_PORT:-80}:80` `ship` publishes
by default, with nothing in front of it, means serving it over plain
HTTP. Put a TLS-terminating layer in front before that port reaches the
public internet.

## Backing up data volumes

Every stateful service (`ship-pgsql-data`, `ship-mysql-data`,
`ship-redis-data`, `ship-seaweedfs-data`, `ship-meilisearch-data`, ...)
persists to a named Docker volume, not a bind mount — `docker volume ls`
lists them, prefixed with the project's directory name. `ship` doesn't
back these up or rotate them; that's left to whatever backup tooling
your deploy target already uses. A one-off manual backup of a single
volume looks like:

```sh
docker run --rm -v <project>_ship-pgsql-data:/data -v "$PWD":/backup \
    alpine tar czf /backup/pgsql-data.tar.gz -C /data .
```

and restoring is the same in reverse (`tar xzf` into a fresh volume
mounted the same way). For anything beyond an occasional manual backup —
scheduled snapshots, offsite storage, point-in-time recovery — use your
database engine's own tooling (`pg_dump`/`mysqldump` via `ship db`, or
the volume backup approach above pointed at a proper backup destination)
rather than relying on the named volume alone.

## Frontend dev server (Vite HMR)

`ship npm run dev` runs Vite's dev server inside the `app` container. Its
port (`${VITE_PORT:-5173}` by default) is published to the host
automatically in development, so the browser can reach it — but Vite itself
needs to know to bind somewhere reachable from outside the container, and to
tell the browser the right address to connect back to for the HMR
WebSocket. Add this to your `vite.config.js` (this is Laravel's own
documented fix for running Vite inside Sail/WSL2 — same underlying problem):

```js
export default defineConfig({
    // ...
    server: {
        host: '0.0.0.0',        // bind inside the container, not just loopback
        port: Number(process.env.VITE_PORT ?? 5173),
        strictPort: true,       // fail fast instead of silently picking another
                                 // port — one the compose file doesn't publish
        hmr: { host: 'localhost' }, // what the *browser* should connect back to
    },
});
```

Reading `process.env.VITE_PORT` rather than hard-coding `5173` matters if
you ever need a non-default port — e.g. two `ship`-managed projects run
side by side (see
[Running more than one project at once](#running-more-than-one-project-at-once)),
or a project that already used a different port before adopting `ship`.
Both the compose port mapping and Vite's own bind port read the exact same
`VITE_PORT` from your project's `.env` (`app`'s `env_file:` loads the same
file Compose interpolates `${VITE_PORT:-5173}` from) — set it once there
and both sides follow it. Only the compose side has a built-in default;
without `?? 5173` here, an unset `VITE_PORT` would leave Vite's own `port`
option `undefined`.

`strictPort` matters more than it looks: without it, if Vite's default port
is already taken *inside the container* (e.g. a previous `ship npm run dev`
that wasn't stopped cleanly), Vite silently starts on the next free port
instead — one the compose file never published — and HMR fails with a
generic connection error that gives no hint the port even changed.

The actual app (Blade-rendered pages, API routes, ...) still serves from
`:80` as normal through `webserver`/nginx — Vite's dev server is a separate
HTTP+WebSocket server on its own port, the same architecture Laravel Sail
uses, not something proxied through the same port as the app. `ship`
doesn't auto-edit an existing `vite.config.js` to add this, since reliably
patching arbitrary existing JS config isn't something worth the fragility —
`ship init` prints this exact snippet as a reminder instead, whenever it
detects `vite` in `package.json` and the existing config doesn't already
look like it handles this.

## Faster file sync on Windows/macOS (Mutagen)

`ship up`'s default dev bind mount (the project root, live-mounted into
`app`/`webserver`) is simple and always in sync, but Docker Desktop's
translation layer between the host filesystem and its Linux VM makes
filesystem-heavy work — `composer install`, `npm install`, a large test
suite — noticeably slower on Windows and macOS than the same thing on
native Linux, where containers talk to the filesystem directly.

[Mutagen](https://mutagen.io/) syncs the project root into the container in
the background instead, which avoids that translation-layer overhead. It's
opt-in, off by default, and a personal machine preference rather than a
project setting — it's pure overhead with zero benefit on Linux, so it
isn't something to bake into `ship.json` for a whole team. Set the
`SHIP_MUTAGEN` environment variable before running `ship up`:

```bash
SHIP_MUTAGEN=1 vendor/bin/ship up
```

`ship up` creates the sync session after the stack starts and waits for the
initial sync to finish before reporting success; `ship down` tears the
session down again automatically. Requires
[Mutagen](https://mutagen.io/documentation/introduction/installation)
itself to already be installed and on `PATH` — `ship up` fails with a clear
error naming it if it isn't.

Never applies in production: the production image bakes the source into
itself at build time (see [Production build](#production-build)), so
there's no bind mount there to begin with, and nothing to sync either way.

`vendor/` and `node_modules/` are deliberately excluded from the sync, for
two reasons — not just the sheer file count. Some Composer packages, and
any npm package with a native build step (`esbuild`, `sharp`, `sass`,
`swc`-based tooling), install platform-specific compiled binaries; syncing
a copy installed on Windows/macOS into the Linux container would hand it
binaries built for the wrong platform outright. The container bootstraps
its own `vendor/` the same way it already does without Mutagen (the dev
image runs `composer install` on boot if `vendor/autoload.php` is
missing); `node_modules/` needs a `ship npm install` the same way a fresh
bind-mounted project would too if you hadn't run one yet — no regression
either way, since bind-mount mode doesn't auto-install it now either.

## Running more than one project at once

Every published port has an env var override, read at `ship up` time from
your shell or a `.env` file in the project root:

| Port | Variable | Default |
|---|---|---|
| App / nginx | `APP_PORT` | `80` |
| Octane runtime (serves HTTP itself) | `APP_PORT` | `8000` |
| Vite dev server | `VITE_PORT` | `5173` |
| Reverb | `REVERB_PORT` | `8080` |
| Mailpit web UI | `MAILPIT_WEB_PORT` | `8025` |

If a default is already taken — by another `ship` project, or an unrelated
container — override it for one invocation:

```bash
APP_PORT=8081 VITE_PORT=5174 REVERB_PORT=8081 vendor/bin/ship up
```

## Customizing the stack

`ship/docker-compose.generated.yml` is exactly that — generated fresh from
`ship.json` on every `ship up`, so hand-editing it directly doesn't stick.
To add your own services, or override anything ship's generated file sets,
add a `docker-compose.override.yml` at your project root — the same file
name and merge behavior [Docker Compose itself documents](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/).
Every `ship` command that shells out to `docker compose` picks it up
automatically if it exists:

```yaml
# docker-compose.override.yml
services:
  adminer:
    image: adminer:4
    ports:
      - '8099:8080'
    networks:
      - ship
```

This works for anything Compose supports — adding a whole new service (like
the Adminer example above), mounting an extra volume, or pointing `app`'s
`build.dockerfile` at your own Dockerfile instead of `ship/Dockerfile`
entirely. Nothing here is ship-specific; it's just Compose doing what it
already does, on top of a file ship happens to regenerate for you.

For the Dockerfile and nginx/php config `ship init` publishes into `ship/`
itself, no override file is needed — `ship up` never rewrites those, so
editing them directly is safe and persists across every `ship up` (until
you re-run `ship init`, which does overwrite them — see the `ship init`
row in [Commands](#commands)).

## Extending ship

Third-party packages can contribute their own services or framework
support without forking this package. List fully-qualified class names in
`ship.json`'s `extensions` array:

```json
{
    "extensions": ["Acme\\Ship\\MeilisearchProService"]
}
```

Each class must already be autoloadable (a normal Composer dependency of
your project) and implement one of the two extension points:

- [`Ship\Contracts\ServiceDefinition`](src/Contracts/ServiceDefinition.php) —
  an optional infrastructure piece (database, cache, runtime, ...). A
  database service can also implement
  [`Ship\Contracts\ProvidesDatabaseShell`](src/Contracts/ProvidesDatabaseShell.php)
  to support `ship db`.
- [`Ship\Contracts\FrameworkAdapter`](src/Contracts/FrameworkAdapter.php) —
  framework-specific console commands (like `ship artisan`) and
  production boot-time release commands.

See [`docs/adding-a-service.md`](docs/adding-a-service.md) for a worked
example.

## Architecture

- [`src/Contracts/ServiceDefinition.php`](src/Contracts/ServiceDefinition.php) —
  the extension point. Every optional piece of the stack (database, cache,
  runtime, storage, ...) implements this.
- [`src/Contracts/FrameworkAdapter.php`](src/Contracts/FrameworkAdapter.php) —
  the seam a framework-specific wrapper hooks into (`ship artisan`),
  without the core package importing that framework.
- [`src/Docker/ComposeFileBuilder.php`](src/Docker/ComposeFileBuilder.php) —
  merges selected services' compose fragments into a generated
  `docker-compose.yml`. Merges rather than overwrites when two services
  target the same compose service name (e.g. an Octane runtime overriding
  `app`'s command while keeping its build/volumes).
- [`src/Docker/EntrypointScriptBuilder.php`](src/Docker/EntrypointScriptBuilder.php) —
  renders the production container's boot-time entrypoint script from the
  matched `FrameworkAdapter`'s `releaseCommands()`.
- [`src/Extensions/ExtensionLoader.php`](src/Extensions/ExtensionLoader.php) —
  resolves `ship.json`'s `extensions` array into registered
  services/adapters, so third-party packages don't require forking this
  one.
- [`stubs/docker/php/Dockerfile`](stubs/docker/php/Dockerfile) —
  multi-stage; `dev`/`builder`/`assets`/`prod` (the `app` service) and
  `dev-nginx`/`prod-nginx` (the `webserver` service) all build from this
  one file.

## Contributing

See [`docs/adding-a-service.md`](docs/adding-a-service.md) for how to add
a new `ServiceDefinition` or `FrameworkAdapter`, and
[`docs/roadmap.md`](docs/roadmap.md) for what's built and what's planned.

```bash
composer install
composer test       # phpunit
composer analyse     # phpstan, level 8
composer cs-check    # php-cs-fixer --dry-run
```

## License

MIT. See [`LICENSE.md`](LICENSE.md).
