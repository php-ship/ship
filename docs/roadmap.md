# Roadmap

What `ship` does today, grouped by area, and what hasn't been started. The
README is the user-facing reference; this file is the maintainer's overview
and records the non-obvious reasons behind each decision.

## What's built

### Extension points

- `ServiceDefinition` (one per optional infrastructure piece),
  `FrameworkAdapter` (one per framework), and the optional
  `ProvidesDatabaseShell` a database service implements for `ship db`.
  `ConfigAwareService` lets a service read `ship.json` before it builds its
  fragment. `RequiresPhpExtensions` lets a service add the PHP extensions
  its client library needs to the app image's `PHP_EXTENSIONS` build arg,
  merged with `ship.json`'s `phpExtensions`. `NeedsAppUrl` lets a browser
  testing service receive the app's internal URL in the container and
  variable it names.
- `ExtensionLoader` resolves `ship.json`'s `extensions` (a list of class
  names) in one pass. `Application` loads them once and passes the
  populated `ServiceRegistry` into every command, so no extension class is
  instantiated twice. A class implementing neither contract produces a
  warning, not a failure.
- An empty `composeFragment()` means the service is absent in that
  environment: no compose service, no env vars injected into `app`.
  Mailpit, SQL Server and the browser testing services use it to stay out
  of production.

### Services

- 22 built-in services: Postgres, MySQL, MariaDB, MongoDB, SQL Server,
  Redis, SeaweedFS, Garage, RustFS, Silo, Octane with
  Swoole/RoadRunner/FrankenPHP, Meilisearch, Elasticsearch, Mailpit,
  Dusk/Selenium, Playwright, Cypress, Node, Reverb, RabbitMQ.
- Named instances (`additionalServices`) for the `database`, `cache`,
  `storage`, `search` and `mail` groups. `SupportsNamedInstances` gives the
  shared naming: compose service `{key}-{name}`, env var prefix `{NAME}_`.
  The name is limited to lowercase letters, digits and underscores because
  it becomes both.
- Every stateful service persists to a named volume in both environments.
  Redis keeps RDB saves on, since losing sessions on a redeploy logs out
  every user.
- Credentials are Compose expressions: an overridable default in
  development, and `${VAR:?...}` in production (`RequiredEnv`), so `docker
  compose` refuses to run without a real value. This applies to database
  passwords, Meilisearch's master key, the storage services' secret keys,
  and Garage's RPC secret and admin token. Usernames and access key ids
  keep plain defaults.
- MySQL: the root password (`DB_ROOT_PASSWORD`) is a separate secret the
  app never receives. The healthcheck pings `127.0.0.1`, because a socket
  ping succeeds against the image's temporary init-phase server before TCP
  connections are accepted. `MySqlUsernameGuard` rejects `DB_USERNAME=root`
  up front, which the official image refuses to start with.
- MariaDB mirrors MySQL: a separate root password, `MySqlUsernameGuard`
  (the mariadb image rejects a root app user too), and the image's own
  `healthcheck.sh --connect`, which goes over TCP and so can't pass against
  the init-phase server. It sets `DB_CONNECTION=mariadb`, Laravel 11+'s
  own driver.
- MongoDB: the image only creates a root user, so the entrypoint writes an
  init script creating an app user that owns only the app's database; the
  root password (`MONGODB_ROOT_PASSWORD`) stays a separate secret. The
  healthcheck pings the container's own address, since the init-phase
  server listens on 127.0.0.1 only. The app gets `MONGODB_URI`, which
  `serviceNames` renaming follows (`_URI`/`_URL` keys, hostname after `@`), and
  `DB_CONNECTION` is left alone: without laravel-mongodb installed there
  is no `mongodb` connection.
- SQL Server runs Developer Edition in development only (an empty
  production fragment, like Mailpit): it isn't licensed for production.
  The image creates nothing but `sa`, so the entrypoint starts SQL Server
  in the background and idempotently creates the app database and a login
  owning it, with values passed as escaped literals, identifiers through
  `QUOTENAME()` and sqlcmd's own variable substitution off. The
  healthcheck logs in as that login, so it only passes once both exist.
  ODBC Driver 18 rejects the self-signed certificate unless told to trust
  it, and Laravel only reads that from an edited `config/database.php`,
  so the app also gets a `DB_URL` carrying `trust_server_certificate=true`.
  `pdo_sqlsrv` is required in production as well, where the app talks to a
  licensed server. amd64-only, so `platform: linux/amd64` is explicit.
- Elasticsearch: single node, security on with the `elastic` user's
  password required in production, plain HTTP inside the network. The JVM
  heap is capped at 1 GB in development only. No `SCOUT_DRIVER` change:
  Laravel has no built-in driver.
- RabbitMQ (the `broker` group, one per project): a fixed `hostname`,
  because RabbitMQ keys its data directory by node name, which otherwise
  follows the container id and orphans the persisted queues on every
  recreate. The management UI is published in development only.
  `QUEUE_CONNECTION` is left alone, as with MongoDB's `DB_CONNECTION`.
- Storage:
  - SeaweedFS has no S3 authentication without an identity file, so its
    entrypoint generates one from the injected credentials at boot
    (JSON-escaped, and `$$`-escaped so Compose doesn't interpolate it).
  - Garage provisions its default key and bucket on boot. Its
    `rpc_public_addr` is substituted at image build time, since the final
    image has no shell.
  - RustFS publishes no console port: the console currently returns
    "AccessDenied" (rustfs/rustfs#8013).
  - Silo publishes its web console, on an instance-scoped port variable.
    `siloImage` selects the standard image or the distroless one of the
    same release: the standard image's RHEL 9 base needs an x86-64-v2 CPU.
    `ship init` asks when Silo is selected. The healthcheck uses `silo
    healthcheck live`, since the distroless image has no curl.
  - RustFS, SeaweedFS and Silo don't create a bucket; the project does,
    once.
- Octane:
  - Runs as `www-data` in production on all three runtimes.
  - `--watch` in development. For Swoole and RoadRunner it's decided at
    container boot by whether `node_modules/chokidar` exists, because
    passing it without chokidar crash-loops Octane's watcher. FrankenPHP
    uses a native Caddy watch directive and needs no check.
  - FrankenPHP has its own Dockerfile (Debian base). The base image's
    healthcheck is disabled: it reports whether Caddy is alive, not whether
    the app can serve requests.
- Reverb is a separate service built from the app's image.
  `ComposeFileBuilder::alignReverbWithApp()` gives it the app's injected
  environment and build args, `SHIP_RUN_AS`/`SHIP_HOST_USER`, the Mutagen
  volume, and `SHIP_DEV_SKIP_INSTALL`, since `ReverbService` can see none of
  those itself.
- Browser testing (development only; one of Dusk, Playwright, Cypress):
  each browser runs in its own container and reaches the app at its
  internal address (`NeedsAppUrl`): `http://webserver`, or
  `http://app:8000` with an Octane runtime, following renames.
  - Dusk gets it as `APP_URL` on `app`, since Dusk visits `APP_URL`. Only
    then: anywhere else it would override the project's own `APP_URL`,
    because `environment:` beats `env_file:`.
  - Playwright runs its browser server (`run-server`, version read from the
    image tag so a bump moves both); the test runner in `app` connects via
    `PW_TEST_CONNECT_WS_ENDPOINT`, so the Alpine app image needs no
    browsers. The base URL is `PLAYWRIGHT_BASE_URL`, leaving `APP_URL`
    alone. The project's `@playwright/test` must match the server's minor
    version.
  - Cypress runs tests in its browser's process, so its container idles
    (`sleep infinity`; the image's own entrypoint runs the suite once and
    would loop under the restart policy) with the project mounted, and gets
    `CYPRESS_BASE_URL`, which Cypress applies itself. `app` gets
    `CYPRESS_INSTALL_BINARY=0`: the binary doesn't support Alpine, and the
    container brings its own. Under Mutagen the mount is the synced volume.
  - The browsers can't reach Vite's dev server at `localhost:5173`, so
    tests run against built assets.
- Node is installed in the base image at `ship.json`'s `node` version,
  copied from the official node image. Selecting the `frontend` group
  changes nothing else.

### Compose generation

- `ComposeFileBuilder` merges fragments rather than overwriting, so a
  runtime can replace `app`'s command while keeping its build and volumes.
  List keys are deduped after merging; `build` is shallow-merged.
- `webserver` (nginx) exists only when no Octane runtime is selected.
- Every service gets `restart: unless-stopped`. Named volumes are derived
  from the merged services.
- Every file carries a top-level `name:` (`ship.json`'s `name`, or the
  project directory's basename, sanitized by `ProjectName`), so volumes and
  network aliases don't depend on which directory the file is in.
- `serviceNames` renames services as their fragments are built and fixes
  `depends_on` references. A service's hostname env var follows the rename
  (keys ending in `_HOST` or `_ENDPOINT` only), while driver identifiers
  such as `DB_CONNECTION=mysql` are left alone. `externalNetwork` attaches
  the app to a pre-existing network.
- `build()` rejects renames that collide, duplicate `additionalServices`
  names, an additional instance of a service that doesn't support one, and
  invalid or colliding `processes` names.
- Development ports bind `127.0.0.1` (`DevPortBinding`). Production
  publishes nothing unless `publishPorts` is true, because a
  Docker-published port bypasses host firewalls.
- Vite's port mapping uses `${VITE_PORT:-5173}` on both sides, so one
  `.env` value drives the mapping and the port Vite binds.
- `app` and `reverb` load an optional `.env` through `env_file`; `webserver`
  doesn't, since its config is static.
- The dev `app` gets `host.docker.internal:host-gateway` so Xdebug can
  reach the IDE.

### Images

- One `ship/Dockerfile` builds `dev`, `builder`, `assets`, `prod` (the app)
  and `dev-nginx`/`prod-nginx` (the webserver). `Dockerfile.frankenphp`
  mirrors the PHP stages on FrankenPHP's base image.
- Fixed extension set: `pdo_pgsql`, `pdo_mysql`, `intl`, `mbstring`,
  `opcache`, `pcntl`, `redis`, `zip`, `sockets`. `zip` (laravel/dusk,
  spreadsheet and archive libraries) and `sockets` (RoadRunner,
  php-amqplib) are common and cheap, so they're always in rather than
  declared per service; `RequiresPhpExtensions` is for heavier ones.
  `phpExtensions`, plus whatever selected services require
  (`RequiresPhpExtensions`), adds more through
  `mlocati/docker-php-extension-installer`.
- Apart from the PHP and Node versions `ship.json` sets, the Dockerfiles'
  build inputs are pinned to exact versions: Composer, nginx, the PECL
  extensions, and the extension installer (with a checksum). Service images
  carry a version tag. `renovate.json` keeps all of them current, including
  the image tags written as PHP strings in `src/Services/`.
- The `assets` stage runs `npm ci` when a lockfile exists (`npm install`
  otherwise), builds, and removes `node_modules`.
- Dev containers run as root, because a bind mount keeps the host's
  UID/GID and a fixed container user rarely matches it. `hostUser` opts in
  to building the dev image with the host's UID/GID instead; the entrypoint
  and the `ship exec` family then run as that user. It is skipped, with a
  message, on native Windows, as root, or under `SHIP_MUTAGEN`.
- The production entrypoint (`EntrypointScriptBuilder`) is generated per
  build from `FrameworkAdapter::releaseCommands()`. It runs those commands,
  chowns `storage`, `bootstrap/cache`, `database` and `var` to `www-data`,
  then execs the real process, dropping to `SHIP_RUN_AS` when set (`su-exec`
  on Alpine, `setpriv` on Debian). php-fpm keeps a root master; Octane,
  Reverb and `processes` drop.
- Both php.ini stubs set upload limits to match nginx's 100M and turn off
  `expose_php`. nginx hides its version, passes only `/index.php` to
  PHP-FPM, and returns 404 for any other `.php` path.
- Xdebug is installed in the dev image with `xdebug.mode=off`; set
  `XDEBUG_MODE` in `.env` to enable it.

### Commands

- `init`, `up`, `down`, `exec`, `shell`, `logs`, `db`, `config:test`,
  `build`, `release`, and `composer`/`npm`/`artisan`/`console` through
  `ProxyCommand`.
- `ProxyCommand` and `ExecCommand` forward raw argv, so flags meant for the
  inner command aren't parsed by `ship`.
- `ComposeCommand::baseArgs()` builds the shared `docker compose` prefix
  and adds `docker-compose.override.yml` when present, except for
  production builds. `execPrefix()` adds `--user` for the app service when
  the generated file carries the `hostUser` marker.
- `ship init`:
  - Re-running it defaults every prompt to the existing selection, keeps
    existing `additionalServices`, and carries over every field it doesn't
    prompt for. It reads the old file leniently
    (`ShipConfig::tryFromFile()`), so one invalid field doesn't discard the
    rest.
  - Publishes `ship/`, records the installed version in
    `ship/.ship-version`, and adds the secret-related entries to
    `.dockerignore` and `.gitignore`.
  - Warns when Vite's config needs the dev-server snippet, and when Reverb
    is selected without `laravel/reverb` installed.
  - Uses Laravel Prompts when available and falls back to a numbered
    prompt.
- `ship up`:
  - Warns when the published stubs come from a different `ship` version or
    the nginx upstream no longer matches `serviceNames`. It never rewrites
    published files.
  - After `docker compose up` it checks that every service is running
    (retrying once), waits for each declared healthcheck for as long as
    that healthcheck's own budget, and confirms every published port
    actually bound on the host.
- `ship doctor` reports the installed and published versions and every
  container's state and health from `docker compose ps`, with a recovery hint.
  It reads the compose file `ship up` generated rather than regenerating it,
  so it never changes what `ship exec` runs against. `StackHealth` holds the
  logic, testable without Docker. Python Ship has the same command.
- `ship config:test` validates `ship.json`, builds both compose files,
  and checks required production variables, the nginx upstream and
  `DB_USERNAME=root`, without Docker. It reports every problem in one pass.

### Mutagen sync

- Opt-in through `SHIP_MUTAGEN=1`, a per-machine choice: it helps on
  Windows/macOS and is overhead on Linux. Development only.
- `app`, `webserver` and `reverb` share a named volume instead of the bind
  mount. `vendor/` and `node_modules/` are excluded, since they can hold
  platform-specific binaries.
- `ship up` starts the sync before its service checks, waits for the
  "Watching" state, then runs `composer install` in the container. The dev
  entrypoint skips its own install (`SHIP_DEV_SKIP_INSTALL`) so it can't
  race that one with a partially synced tree.
- `ship down` terminates the session. Sessions are matched by a label
  hashed from the project path.

### Production releases

- `ship build` builds every project-owned image under a local tag.
  `ship release --tag` runs the same build (`ProductionBuildRunner`) with
  the real tag and assembles `dist/ship/<tag>/`: a compose file with
  `build:` stripped, `.env` copied from `.env.production`, one `docker save`
  tar per unique image, `release.json`, and `deploy-commands.sh` when
  `deployCommands` is set.
- Both write `ship/docker-compose.production.yml`, never the dev compose
  file, and both fail before invoking Docker when a required production
  variable is missing from `.env.production`.
- `ProductionImagePlan` groups services with an identical `build:` (the app
  and every `processes` entry) under one tag and one tar.
- `deployCommands` run once per deploy. The script first brings up the
  infrastructure services (`DeployPlan::infrastructureServices()`: anything
  not built from `ship/Dockerfile`), then runs each command in a one-off
  container of the app image and stops at the first failure.
- `processes` become separate services from the app's build config, with
  its environment and networks, no ports, `SHIP_RUN_AS=www-data` and a 60s
  stop grace period. `$` is escaped so Compose doesn't interpolate the
  command.
- The release directory is owner-only and its `.env` is `0600` on POSIX. On
  Windows `ship release` warns that `deploy-commands.sh` can't be made
  executable.
- `.dockerignore` excludes `**/.env`, `**/.env.*`, `auth.json`, `.npmrc`
  and `/dist/ship`, and is re-applied on every build, so no secret or
  earlier release ends up in an image.
- `ship release` requires `--tag` when stdin isn't a terminal instead of
  prompting.

### Configuration

- `schema/ship.schema.json` is the ship.json schema shared with Python Ship,
  kept identical in both repositories. `ship init` writes `$schema`, and
  `ShipConfigTest` checks that every field `toFile()` can write is declared.
- PostgreSQL's key is written `postgres` (Python Ship's name) and `pgsql` is
  accepted. `ShipConfig::SERVICE_ALIASES` maps it to the registry key `pgsql`
  on read, so the compose service, volumes and `DB_CONNECTION=pgsql` are
  unchanged for existing projects.

- `ShipConfig::fromFile()` validates every field's type and the name
  formats, and reports problems with a message naming `ship.json`.
- `EnvFile` reads `.env`/`.env.production`: it handles a BOM, `export`
  prefixes, quoted values with escapes, and trailing comments.

### Tooling

- CI runs tests, PHPStan (level 8) and php-cs-fixer on Ubuntu, macOS and
  Windows with PHP 8.2 to 8.5.
- A `docker-build` job runs `init`, `up`, `db`, `build` and `release`
  against a fixture Laravel app and a real Docker daemon, boots the stack
  from the release artifact alone, and repeats the dev checks for an
  Octane/Swoole fixture and a `SHIP_MUTAGEN=1` fixture.
- Workflow permissions are read-only, actions are pinned to commits, and
  the Mutagen download is checksum-verified.

## Not started

- Cross-service coordination beyond what `ComposeFileBuilder::build()`
  computes itself (the app URL, version build args, Reverb alignment). A
  service that needs to know about other selected services has no general
  mechanism yet; design one when a concrete need comes up.
