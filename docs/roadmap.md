# Roadmap

## What's built

- Extension contracts: `ServiceDefinition` (one per optional infrastructure
  piece), `FrameworkAdapter` (one per target framework), and the optional
  `ProvidesDatabaseShell` add-on a database `ServiceDefinition` can
  implement for `ship db`. `ExtensionLoader` resolves `ship.json`'s
  `extensions` array (a list of FQCNs) into registered instances of each,
  used independently by `Application`, `InitCommand`, and `UpCommand`.
- 11 built-in `ServiceDefinition`s: Postgres, MySQL, Redis, SeaweedFS
  (default object storage) + Garage (alt driver), Octane x
  Swoole/RoadRunner/FrankenPHP, Meilisearch, Mailpit, Dusk/Selenium, Node,
  Reverb.
- Two built-in `FrameworkAdapter`s: `LaravelAdapter` (`ship artisan`,
  `php artisan optimize` at production boot) and `SymfonyAdapter` (`ship
  console`, `php bin/console cache:clear` at production boot).
- `ComposeFileBuilder` merges fragments (not overwrites) so a runtime can
  override `app`'s command while keeping its build/volumes, derives
  top-level named volumes generically, backfills `PHP_VERSION` onto any
  service building a `ship/Dockerfile` PHP stage, switches whether
  `webserver` (nginx) exists based on runtime selection, and gives every
  service a `restart: unless-stopped` policy so a crash (or host reboot)
  recovers on its own instead of staying down. Merging list-type keys
  (`networks`, `depends_on`, `ports`, `volumes`) across two fragments
  landing on the same service dedupes the result, since more than one
  fragment can independently default a value it doesn't set itself
  (every fragment missing `networks` defaults to `["ship"]`) --
  concatenating without deduping there produces a value Compose's own
  schema rejects.
- One `ship/Dockerfile` builds five targets: `dev`, `builder`, `assets`,
  `prod` (the `app` service), and `dev-nginx`/`prod-nginx` (the
  `webserver` service). `Dockerfile.frankenphp` mirrors the same stage
  structure for the FrankenPHP runtime, which needs a different base
  image entirely. The `dev` stage's php-fpm pool runs as root (`sed`
  into `www.conf` at build time, plus `--allow-to-run-as-root`), not
  the image's default `www-data` -- `dev` bind-mounts the project root
  straight from the host (see `baseServices()`), so `storage/`,
  `bootstrap/cache/`, etc. keep whatever host UID/GID created them,
  almost never `www-data`'s on a real Linux host. Without this, every
  request fails outright the moment it needs to write anything under
  the project root (`tempnam(): file created in the system's temporary
  directory`, from Laravel's atomic config/view cache writes) --
  invisible on Docker Desktop's Windows/macOS bind mounts, which don't
  enforce real Unix permissions, but reproduces on any native Linux
  host, this project's own CI included. Chowning the bind mount to
  match isn't an option -- that changes the *host's* files. `prod`
  keeps `www-data`, since its files are baked into the image at a
  known ownership instead (see `EntrypointScriptBuilder` below).
- `EntrypointScriptBuilder` renders the `prod` stage's `ENTRYPOINT` script
  from whatever `FrameworkAdapter::releaseCommands()` returns, generated
  fresh by every `ship up` since its content depends on which adapter
  matches.
- Commands: `init`, `up` (`--prod` for production), `down`, `exec`,
  `shell`, `db`, `logs`, plus `composer`/`npm`/`artisan`/`console` via
  `ProxyCommand`. `ComposeCommand::baseArgs()` is the shared
  `docker compose -f ... --project-directory ...` prefix every command
  uses, appending a second `-f docker-compose.override.yml` when a
  project has one.
- `app`, `webserver`, and `reverb` all load an optional `.env` from the
  deploy target's filesystem via Compose's `env_file`, never baked into
  the image itself.
- `ship up` verifies the stack it just started, not just that `docker
  compose up --build -d` exited 0. It checks every expected service
  against `docker compose ps --status running`, retrying once for
  anything not yet up (self-heals a Docker Compose/Desktop concurrency
  quirk under simultaneous image builds, where a late-building service
  can otherwise sit at "Created" without ever starting); then, for
  anything with a published port, confirms the port actually bound on
  the host, not just that the container is running -- Docker can leave
  a service "running" while silently dropping its port publish if
  something else already owns that host port, and `docker compose
  port` reports that failure as the literal string "invalid IP:0", not
  as empty output or a command error, so a naive check misses it
  entirely. Between those two checks, any service that declares a
  `healthcheck` (MySQL, Postgres, Redis) is also waited on to actually
  reach "healthy", not just "running" -- a fresh volume's first boot
  can take several seconds after the process starts before it accepts
  real connections, and without this a `ship up` immediately followed
  by `ship exec app php artisan migrate` could race ahead of the
  database and fail with a connection error even though `ship up`
  itself had already reported success. All three checks fail loudly
  with a clear message naming the affected service instead of exiting
  quietly successful.
- `EntrypointScriptBuilder`'s generated script chowns `storage`,
  `bootstrap/cache`, `database`, and `var` (existence-checked, so this
  stays framework-agnostic) to `www-data` after release commands run
  and before handing off to the real process. Plain php-fpm's
  request-handling workers run as `www-data`, but everything before
  that point — the image build, `artisan optimize`'s view cache —
  runs as root; without this, anything a worker needs to write at
  request time (an uncached view, Laravel's own default SQLite-backed
  session driver writing to `database/database.sqlite`) hits a
  permission error against root-owned files. Scoped to those specific
  directories, not the whole tree — a recursive chown over `vendor/`
  too once took tens of seconds and blocked php-fpm from ever starting
  on a real Laravel app.
- Node's version is configurable via `ship init`'s "Node.js version"
  prompt (`ShipConfig::$nodeVersion`, defaulting to 24), backfilled as
  a `NODE_VERSION` build arg the same way `PHP_VERSION` is. Both
  Dockerfiles copy the official `node` image's binaries in at that
  pinned version (matching musl/Alpine or glibc/Debian per base image)
  rather than installing via the OS package manager, which only ever
  carries one fixed version tied to that OS release regardless of what
  `NODE_VERSION` says.
- `ship init` records the installed `php-ship/ship` version into
  `ship/.ship-version` (via `Ship\Support\ShipVersion`, Composer's
  `InstalledVersions` API); `ship up` reads it back and warns — never
  re-publishes on its own, since a project may have hand-edited those
  files — when it doesn't match what's currently installed, so
  upgrading `ship` without re-running `init` no longer silently leaves
  a project on stale stub files.
- `renovate.json` keeps every pinned version current: Composer
  dependencies, GitHub Actions versions, and the real Dockerfiles'
  `FROM` lines via Renovate's own built-in managers, plus a custom
  regex manager for the Docker image tags pinned as PHP string
  literals in `src/Services/*.php` (`'image' => 'mysql:9.7'` and
  friends), which the built-in dockerfile manager can't see since
  they're not in an actual Dockerfile. Grouped into one PR per run,
  not one per image, so a version bump gets a single review pass.
  Needs the Renovate GitHub App enabled on the repo to actually run.
- Multiple named instances of the same kind of service -- a Postgres
  primary plus a MySQL connection into a different system, a second
  Redis kept separate from the default cache/session store -- via
  `ship.json`'s `additionalServices` (`ship init` offers this
  interactively too, right after the main picker). `database`,
  `cache`, `storage`, `search`, and `mail` support it; a runtime,
  frontend toolchain, testing driver, or broadcasting server doesn't,
  since a second one of any of those isn't a coherent idea.
  `Ship\Services\SupportsNamedInstances` gives a `ServiceDefinition`
  the naming convention (`"{key()}-{instanceName}"` for the compose
  service and named volumes, an uppercased-instance-name env var
  prefix) that `ComposeFileBuilder`, `DbCommand`, and the service
  itself all independently have to agree on; every built-in service in
  a supporting group uses it. `environmentVariables()` and
  `composeFragment()` both take an optional `$instanceName` (null for
  the default instance, matching every existing project's ship.json
  byte-for-byte -- this was additive, not a breaking change for
  existing single-instance projects, only for third-party
  `ServiceDefinition` implementations, which now need the parameter in
  their own method signatures too). Verified live: a real Postgres +
  MySQL stack, both reachable from the same Laravel app through two
  separate `config/database.php` connections at once, `ship db` and
  `ship db analytics` each opening the right one.
- CI matrix across Ubuntu/macOS/Windows x PHP 8.2/8.3/8.4, plus a
  separate `docker-build` job that actually runs `ship init`/`up`/
  `up --prod` against a real fixture Laravel app and a real Docker
  daemon — building both images, migrating a real database, and
  checking the app actually answers over HTTP in both modes. Invokes
  `bin/ship` directly against a plain fixture app rather than through a
  Composer dependency, since `$projectRoot` is just `getcwd()` (see
  `bin/ship`) — that sidesteps a real limitation where a Composer path
  repository pointing at this checkout can't resolve inside an isolated
  Docker build context, which would otherwise make the production build
  half of this job untestable.
- `InitCommand::select()`'s Laravel Prompts branch has real test coverage
  (`InitCommandLaravelPromptsTest`), run in its own isolated process
  (`#[RunInSeparateProcess]`) against a hand-rolled fake
  `Laravel\Prompts\select()` (`tests/Fixtures/fake-laravel-prompts.php`)
  rather than the real package as a dev dependency -- the real
  `laravel/prompts` autoloads its helper functions globally for the
  whole PHP process via Composer's "files" autoloading, which would make
  every *other* `InitCommand` test (all of which specifically exercise
  the `ChoiceQuestion` fallback on the assumption that `laravel/prompts`
  isn't installed at all) also route into the Prompts branch the moment
  they share a process with it. Still skipped on Windows, matching
  `canUseLaravelPromptsInteractiveUi()`'s own `PHP_OS_FAMILY` gate --
  CI's ubuntu-latest/macos-latest matrix legs are what actually run it.

## Known gaps

- **`ProxyCommand` and `ExecCommand`'s raw-argv forwarding** (both read
  `$_SERVER['argv']` directly rather than Console's parsed arguments, so
  a flag meant for the executed command — `-m` in `artisan make:model
  Post -m`, `--force` in `ship exec app php artisan migrate --force` —
  isn't swallowed as an unrecognized option on `ship` itself) assumes
  the command name appears exactly once in `argv` and isn't itself the
  value of an earlier option — fine today, but would need revisiting if
  a global option is ever added before the command name.

## Not started

- Mutagen-based sync mode as an opt-in alternative to bind mounts on
  Windows/macOS, for projects where bind-mount I/O is a bottleneck.
- Cross-service coordination beyond what `ComposeFileBuilder::build()`
  already computes generically (`APP_URL`, `PHP_VERSION` backfill). A
  service that needs to know about *other* selected services beyond
  that has no clean mechanism yet — document the specific need if one
  comes up, rather than speculatively designing for it now.
