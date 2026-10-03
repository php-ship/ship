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
- `ProxyCommand` and `ExecCommand` both read raw, unparsed argv rather
  than Console's own parsed arguments, so a flag meant for the executed
  command — `-m` in `artisan make:model Post -m`, `--force` in `ship
  exec app php artisan migrate --force` — isn't swallowed as an
  unrecognized option on `ship` itself. Finding *where* the forwarded
  arguments start uses `ArgvInput::getRawTokens(strip: true)` (via
  Symfony's own `getFirstArgument()`), not a plain search for the
  command's name in `argv` — that search took the first literal match
  anywhere, including as some earlier global option's value, which
  `getRawTokens()` correctly skips instead since it knows the full
  option definition. It still can't tell apart a forwarded command name
  from an *identical-looking* value some earlier option happened to
  take (e.g. a hypothetical `--env exec` ahead of `ship exec ...`
  itself) — an upstream `getRawTokens()` limitation (it re-finds the
  split point by string equality, not the position `getFirstArgument()`
  actually used), narrow enough not to be worth working around given
  `ship` defines no such global options today. Falls back to the old
  `$_SERVER['argv']` search only when `$input` isn't a real `ArgvInput`
  (`CommandTester`'s `ArrayInput` in tests, never a real invocation).
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
  `ship db analytics` each opening the right one. The instance name
  itself is restricted to lowercase letters, digits, and underscores,
  starting with a letter -- it becomes both a Compose service name
  suffix and an environment variable prefix, and confirmed live that a
  space in it makes `docker compose config` reject the whole generated
  file outright, with an error that never points back to this prompt.
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
- `Ship\Sync\MutagenSync` -- opt-in Mutagen file sync (`SHIP_MUTAGEN=1`,
  see README's own section) as an alternative to `ship up`'s default dev
  bind mount, for projects where Docker Desktop's host-filesystem
  translation layer is a real bottleneck (Windows/macOS only; pure
  overhead on Linux, so a personal environment variable, not a
  `ship.json` setting). Orchestrated directly by `ship up`/`ship down`
  (`mutagen sync create`/`terminate`), not the separate `mutagen-compose`
  plugin some tutorials assume -- that's a second binary beyond core
  Mutagen ship doesn't otherwise need. `ComposeFileBuilder` swaps
  `app`/`webserver`'s bind mount for a shared named volume in this mode
  (a live bind mount would fight the sync over the same path; the named
  volume lets `webserver`, which needs the same tree for its own
  static-file serving, share the identical already-synced content instead
  of needing its own separate sync session). `vendor/`/`node_modules/`
  are excluded from the sync -- both can contain platform-specific
  compiled binaries a Windows/macOS install would hand the Linux
  container the wrong build of. `ship up` blocks until the initial sync
  actually reaches Mutagen's own "Watching" steady state before reporting
  success, not just until session creation returns, since the container
  starts out empty at that path until the sync backfills it.

  That "starts out empty" fact caught a real bug during live
  verification, not just a hypothetical: the dev entrypoint's existing
  `composer install`-if-`vendor/`-missing fallback assumed a bind mount,
  where the real project files (if not `vendor/`) are always already
  there. Against the named volume this mode uses instead, `composer.json`
  itself doesn't exist yet either at first boot -- `composer install`
  failed outright, and `set -e` turned that into the whole entrypoint
  script exiting, which `restart: unless-stopped` turned into an infinite
  crash loop racing against Mutagen's own sync, which needs a *running*
  container to inject its agent into. Fixed by also requiring
  `composer.json` to actually exist before attempting the install --
  letting php-fpm boot against a harmlessly empty directory instead,
  until Mutagen catches up.

  A second real bug, this one caught by CI rather than local
  verification (v0.3.0 shipped with it, fixed in the next release):
  excluding `vendor/` from the sync means nothing ever installs it in
  this mode at all -- the entrypoint's own fallback only ever runs at
  container *boot*, before the sync session exists yet, so it always
  finds `composer.json` missing too and skips, exactly as designed for
  the crash-loop fix above. Nothing re-triggers it once the sync
  actually lands. The result: `ship up` itself reported success, but
  `ship exec app php artisan migrate` immediately after failed on a
  missing `vendor/autoload.php` -- caught by CI's own docker-build job
  running exactly that sequence, not by any local testing, since local
  verification up to that point only checked raw file sync, never an
  actual command needing Composer dependencies. Fixed by running the
  same guarded install (`composer.json` present, `vendor/autoload.php`
  missing) inside the container via `docker compose exec` once the sync
  reaches "Watching," instead of relying on the entrypoint. Idempotent
  by the same guard -- a second `ship up` costs one quick `exec` since
  `vendor/` persists in the named volume like everything else written
  inside the container.

  A third real bug, also caught by CI (v0.3.1 fixed the one above but
  still shipped with this one, fixed in the next release): the
  `vendor/` fix got `artisan migrate` working, but every actual HTTP
  request to the app still 403'd with nginx's own "is forbidden (13:
  Permission denied)". `webserver`'s dev-nginx stage never runs as
  root -- unlike `app`'s dev php-fpm pool, which already does, for the
  same underlying reason (see that `RUN sed` line's own docblock) --
  so its unprivileged worker processes couldn't read files Mutagen had
  just synced in, which land owned by whatever user Mutagen's own
  agent injection runs as via `docker exec`. Fixed the same way:
  `sed`-ing nginx's own `user nginx;` directive to `user root;`, dev
  only -- `prod-nginx`'s files are baked into the image at build time
  at a known, consistent ownership, so it keeps nginx's own default.

  Verified live end-to-end against a real Docker daemon and the real
  `mutagen` binary beyond all three bugs above (also tracking down a
  fourth gotcha along the way: a `mutagen` install missing its separate
  agent-bundle archive fails sync creation outright with no indication
  why beyond "unable to locate agent bundle") -- covered by CI's
  `docker-build` job too, checking a real file round-trip in both
  directions and that `ship down` actually terminates the sync session,
  not just unit tests around the deterministic parts
  (`ComposeFileBuilderMutagenTest`, `MutagenSyncTest`).
- Direct test coverage for everything that previously had none:
  `ShipConfig` (defaults, malformed-file handling, round-trip),
  `Application` (command registration with no `ship.json` yet, a
  malformed one, a valid extension, framework-adapter detection),
  `ServiceRegistry` (including a check that every built-in
  `ServiceDefinition` is actually in `defaults()`, guarding against one
  getting written and registered nowhere), `ProcessRunner` (against real
  processes, not mocks -- the same standard this project already holds
  Docker-touching code to), and the eight `ServiceDefinition`s that
  previously had none of their own (only ever exercised indirectly
  through `ComposeFileBuilderTest`). `DownCommand`, `LogsCommand`, and
  `ShellCommand` each gained a small `buildCommand(InputInterface)`
  extraction so their real (if simple) conditional logic is testable
  without spawning a real process -- the same pattern
  `ProxyCommand`/`ExecCommand` already used for their own raw-argv
  handling.
- Configurable compose service names (`ship.json`'s `serviceNames`, a
  default-name => custom-name map covering "app"/"webserver" and any
  selected database/cache/etc.) and an opt-in attachment to a
  pre-existing, externally-managed Docker network (`externalNetwork`) --
  lets several `ship`-managed projects share one Docker network (each
  reaching common infrastructure another compose project already runs
  there) without colliding on whichever service's default network alias
  another project on that network already uses. Hand-edited, not
  prompted by `ship init`, the same as `extensions` -- almost no project
  needs this.

  Implemented as a rename applied to each service's own fragment as it's
  built (`ComposeFileBuilder::applyService()`/`renameFragmentKeys()`),
  not a global find-and-replace afterwards, plus a narrower final pass
  fixing up `depends_on` references elsewhere (currently only
  `webserver`'s own `depends_on: ["app"]`). No `ServiceDefinition` (Octane
  runtimes merging into "app", `webserver`'s `depends_on`, ...) needs to
  know a project renamed its own services -- they still work against the
  fixed `app`/`webserver`/`mysql`/... keys they've always used, unaware
  anything downstream renames the result.

  A real bug surfaced building this, not caught until an actual test
  asserted the *right* thing rather than just the renamed thing: a
  service's own hostname env var (`DB_HOST` => "mysql") has to follow a
  rename or the app can no longer reach it, but some services also set an
  unrelated driver identifier that happens to be spelled exactly like
  their own compose name by coincidence (`RedisService`'s own
  `CACHE_STORE`/`SESSION_DRIVER` => "redis", `MySqlService`'s own
  `DB_CONNECTION` => "mysql") -- a first pass keyed only on *value*
  equality renamed those right along with the real hostname, which would
  have silently changed the app's own cache driver to a name Laravel
  doesn't recognize the moment anyone renamed their "redis" service.
  Fixed by also requiring the *key* to actually look like a hostname
  (ends in `_HOST` or `_ENDPOINT`) before touching a value at all --
  caught by a unit test asserting `CACHE_STORE` survives a Redis rename
  unchanged, not by any live Docker check, since nothing about it
  actually depends on a real daemon. `DbCommand` (`ship db`) needed its
  own fix too -- it independently recomputes a service's compose name at
  command-execution time, so it has to resolve that same name through
  `serviceNames` itself rather than reusing whatever `ComposeFileBuilder`
  decided when the compose file was generated.

  Verified live against a real Docker daemon, both scenarios: a fixture
  project renamed to `client-app`/`client-web`, attached to a real
  external network holding a separate MySQL container, actually reached
  it by hostname (a real TCP connection all the way to MySQL's own
  auth/TLS negotiation, not just DNS resolving), while the webserver
  container stayed off that network entirely; a second fixture selecting
  `ship`'s own MySQL renamed to "client-db" came up correctly, `DB_HOST`
  resolved to "client-db" while `DB_CONNECTION` correctly stayed "mysql",
  and `ship db` (no instance argument) resolved through the rename and
  ran a real query against it.
- Two more `storage` group alternatives, `rustfs`/`RustFsService` and
  `silo`/`SiloService`, alongside the existing SeaweedFS/Garage --
  MinIO was deliberately never one of the four: its own community
  edition abandoned prebuilt binaries and gutted its web console, which
  is exactly what motivated looking at alternatives instead of just
  adding it directly. Silo (pgsty/silo) is a community-maintained fork
  of MinIO's actual server codebase restoring both; RustFS is an
  independent, from-scratch Rust rewrite.

  Verified live against a real Docker daemon before committing to
  either, not just from documentation, which is exactly what caught two
  real gaps: (1) neither has a Garage-style `--default-bucket` flag --
  confirmed directly that a write to a bucket that was never created
  fails outright with "NoSuchBucket," so unlike Garage, the app's own
  bucket needs creating by hand once, via a console or any S3 client;
  (2) RustFS's own web console -- the actual reason to reach for either
  of these over SeaweedFS/Garage -- currently just returns the S3 API's
  own "AccessDenied" response instead of rendering, on both
  `--console-enable` and an explicit `--console-address` flag, which
  turned out to match a currently-open upstream bug
  (rustfs/rustfs#8013), not a misconfiguration on this end. `RustFsService`
  is deliberately positioned the same as SeaweedFS/Garage (no published
  console port) until that's fixed upstream, while `SiloService` publishes
  one -- verified live to be a real, working HTML/JS console, not just a
  200 status.

  Also confirmed live: RustFS's container runs as a non-root user
  (10001:10001) baked into the image with `/data` already chowned to
  match, so ship's own named-volume pattern (not a bind mount) for its
  dev data Just Works without needing any of the manual host-side
  `chown` the image's own docs otherwise call for -- Silo runs as root,
  so this never came up for it at all. Both storage services support
  `additionalServices` (a second, differently-purposed instance) like
  SeaweedFS/Garage already do; since both host-publish a console port
  (Silo always, RustFS once its bug is fixed), that port's own env var
  name is instance-scoped (e.g. `ARCHIVE_SILO_CONSOLE_PORT`) so a second
  named instance can't silently collide with the default one on the same
  host port -- a class of collision no existing storage service had to
  consider before, since neither SeaweedFS nor Garage publishes anything
  to the host at all.
- Fixed a real bug reported from live use of a project actually built on
  `ship` (two real Laravel apps sharing infrastructure via
  `serviceNames`/`externalNetwork`): `APP_URL` was unconditionally
  injected into the compose `environment:` block for every project,
  which always wins over whatever real, host-reachable `APP_URL` the
  project's own `.env` already set (`environment:` always beats
  `env_file:`, see `ComposeFileBuilder::OPTIONAL_ENV_FILE`'s own
  docblock). That silently rewrote every user-facing absolute URL
  (queued emails, signed URLs, artisan command output) to an internal
  Docker hostname (`http://app`/`http://webserver`) no browser outside
  the container can resolve — invisible in the common case of hitting
  `http://localhost` directly in a browser, but broken for anything
  generated outside that one request. The only genuine consumer of that
  internal hostname is Dusk's own Selenium container, which really does
  need it (a separate container reaching "app"/"webserver" over the
  `ship` network, not any host-reachable URL) — fixed by only injecting
  `APP_URL` at all when Dusk is selected; every other project now keeps
  whatever its own `.env` already sets, untouched. Verified live: a
  fixture with a custom `.env` `APP_URL` (a non-default port and
  hostname) came through into the "app" container completely unmangled.
- Fixed another real bug from the same report: Vite's dev server port
  mapping only ever let the *host* side follow `${VITE_PORT:-5173}` --
  the container side was a fixed `5173` regardless, so a project whose
  own `vite.config.js` actually listens on a different port (reading
  its own `VITE_PORT`) never got that port published at all, and HMR
  never connected. Both sides now read the same `${VITE_PORT:-5173}` --
  README's Vite HMR section and `ship init`'s own printed reminder
  snippet updated to read `process.env.VITE_PORT` in `vite.config.js`
  accordingly, so one `.env` value drives both the compose mapping and
  Vite's own bind port.

  Fixing this surfaced a real regression in `UpCommand`'s own port-bind
  verification: `ensurePublishedPortsAreBound()`'s `containerPortFrom()`
  blindly split every mapping string on `:`, which every *other* mapping
  in this codebase tolerates fine (only ever the host side has
  `${VAR:-default}` syntax, so the container side was always a bare
  trailing literal) -- but Vite's new both-sides mapping has a literal
  `:` inside `${VITE_PORT:-5173}` on the container side too, so the
  naive split grabbed a garbled fragment instead (caught immediately by
  an actual `ship up`, not a unit test: `docker compose port` was handed
  literal garbage and `ship up` failed outright with a nonsense port in
  its own error message). Fixed by reading `docker compose config`'s
  already-fully-resolved view instead of re-parsing the raw generated
  YAML text for this one check -- Compose resolves every
  `${VAR:-default}` the exact same way `docker compose up` itself does
  (env var, then `.env`, then the inline default) and hands back a real
  numeric `target` port directly, so nothing here needs to reimplement
  that resolution by hand. Verified live: a fixture with `VITE_PORT=5199`
  in `.env` published as `5199:5199` and `ship up` completed successfully.
- Configurable PHP extensions (`ship.json`'s `phpExtensions`, a plain
  list of extension names) beyond the fixed set every project already
  gets unconditionally (pdo_pgsql, pdo_mysql, intl, mbstring, opcache,
  pcntl, redis). Requested from real use: a Composer package's own
  platform requirement (gd for maatwebsite/excel, bcmath for a money
  library) that `ship.json`'s service selections give no way to infer,
  which otherwise makes `composer install` fail outright on unmet
  platform requirements. Hand-edited, not prompted by `ship init`, same
  as `extensions`/`serviceNames`.

  Fed to `mlocati/docker-php-extension-installer` (a new
  `ARG PHP_EXTENSIONS=""` in `stubs/docker/php/Dockerfile`, gated so a
  project that never sets this pays no cost -- no network call, no
  extra build time) rather than hand-writing each one's own
  apk-install/pecl-install/enable dance the way the redis extension
  above it already does: that tool already knows every extension's own
  build dependencies, which is exactly what would otherwise need
  duplicating per extension for an open-ended, project-chosen list.
  Fetched from the tool's GitHub Releases URL, not the
  raw.githubusercontent.com one some older guides use -- verified live
  that the raw master URL still works but prints its own "unsupported
  method" warning (and a deliberate sleep) first, so this uses the URL
  the tool's own docs actually ask for. Backfilled onto Reverb's own
  build args the same way `PHP_VERSION`/`NODE_VERSION` already are,
  since Reverb runs the exact same Laravel app and needs the same
  extensions.

  Verified live against a real Docker daemon, not just unit tests: a
  fixture with `phpExtensions: ["gd", "zip", "bcmath"]` built
  successfully and the resulting image's own `php -m` listed all three.

- Octane's own `--watch` flag, dev only, for Swoole and RoadRunner --
  requested from real use, comparing against Laravel Sail's own Octane
  setup, which already passes it. Without it, Octane keeps serving a
  worker process's already-booted code, so a PHP change silently needs
  a manual `php artisan octane:reload` first — surprising for anyone
  used to plain php-fpm, where every request reloads from disk.
  Requires Node (already unconditional in the base image) and the
  project's own "chokidar" npm package — an app-level dependency, not
  something `ship` installs.

  Originally excluded FrankenPHP for the same flag: that runtime has its
  own history of `--watch` hanging every request mid-flight in worker
  mode specifically inside Docker (php/frankenphp#1293). Closed once
  asked directly about it: unlike Swoole/RoadRunner, FrankenPHP has no
  chokidar/Node dependency to gate on at all -- Octane returns a no-op
  for the Node watcher on this runtime and instead injects a native
  `watch` directive straight into FrankenPHP's own Caddyfile (confirmed
  by reading `vendor/laravel/octane`'s own source, not assumed), so
  there's no missing-module crash risk in the first place -- `--watch` is
  simply unconditional in its dev command.

  Verified live against this project's own pinned FrankenPHP image, not
  just the upstream issue being closed: touching a watched file, then
  firing ten consecutive requests across the following ten seconds,
  produced ten 200s around 450ms each -- no hang, which is the actual
  thing #1293 reported. The watcher did not visibly pick up the change
  within that window in this specific environment (Windows, Docker
  Desktop, a bind-mounted volume) -- plausibly the same
  inotify-over-bind-mount unreliability Docker Desktop has everywhere,
  not a FrankenPHP-specific gap, and nothing here currently proves
  Swoole/RoadRunner's own chokidar watcher reloads any more reliably
  under the same conditions (only that it doesn't crash) -- so this
  isn't held to a higher bar than they already are.

- `extra_hosts: [host.docker.internal:host-gateway]` on the dev "app"
  service — requested from real use: Xdebug (installed unconditionally
  in dev, see `stubs/docker/php/Dockerfile`) needs a route back to
  whatever's listening on the host for its own debug connection (the
  IDE), and `host.docker.internal` isn't a real DNS name Docker
  resolves without this. `host-gateway` is the special value Docker
  itself resolves to the actual host gateway IP, on Docker Desktop
  (Mac/Windows) and native Linux alike (Engine 20.10+, added
  specifically for this), so one line covers every platform this
  project targets. Dev only — Xdebug isn't installed in production, so
  nothing there needs it. Verified live: `getent hosts
  host.docker.internal` inside a real "app" container resolved to a
  real address, not just present in the generated compose file.
- Documented running a command on every container boot (dev), e.g.
  `telescope:setup-database` needing to run against a real, reachable
  database on every start, not just once when dependencies are first
  installed. Requested as a `startupCommands`-style `ship.json` hook,
  but that would just be a second, redundant way to do something
  `ship/dev/entrypoint.sh` already supports today: it's a one-time-
  published, hand-editable file `ship up` never regenerates (same as
  the Dockerfile and nginx config), so a project can already add its
  own command there directly, right before the final `exec "$@"`. No
  code change needed — just a README section pointing at it, since the
  gap was that this wasn't documented anywhere, not that it didn't
  work.
- Documented why dev containers run as root and the resulting
  root-owned-files-on-the-host tradeoff, raised from real use on WSL2.
  A deliberate decision, not an oversight — matching a fixed
  unprivileged UID against a bind mount's own host UID (Sail's own
  approach) only works when they happen to coincide, and running as
  root instead works unconditionally, on every host, without ever
  touching host file ownership to force a match. Chose to document the
  tradeoff (and a `chown`-from-inside-the-container workaround) rather
  than switch the default: switching would fix root-owned files on
  hosts where it happens to match, while reintroducing the harder
  permission-denied failure this project already fixed once (see the
  "Fix dev containers 500ing on any real Linux host" entry above) on
  every host where it doesn't.

  Verified live in WSL2, not just described: a fresh `vendor/` written
  by the container really does show up owned by root from the WSL
  user's own `ls -la`, and `ship exec app chown -R $(id -u):$(id -g)
  vendor` really does flip it back to the WSL user afterward.

- A real bug CI caught immediately after the `--watch` addition above
  went public (not caught locally first, unlike most bugs documented in
  this file): unconditionally passing `--watch` crash-loops Octane's
  own watcher subprocess with "Cannot find module 'chokidar'" the
  instant it's missing -- which is most fresh Laravel installs, not a
  rare case, including CI's own plain `laravel/laravel` + `laravel/octane`
  Swoole fixture. Fixed by moving the decision from a static PHP-side
  flag to a shell conditional resolved at container *boot*
  (`if [ -d node_modules/chokidar ]; then ... --watch; else ...; fi`),
  checking the actual mounted project for chokidar's real presence
  instead of assuming it's always there -- the only place that question
  can be answered correctly, and it also means a project adding
  chokidar later just gets `--watch` on its next `ship up`, no
  ship-side change needed.

  Verified live both ways against a real `laravel/laravel` +
  `laravel/octane` Swoole fixture, not just unit tests: without
  chokidar installed, `ship up` now boots cleanly and serves real HTTP
  200s (confirmed `ps aux` inside the container shows `octane:start`
  running *without* `--watch`, no crash-loop); after `npm install
  --save-dev chokidar` and a restart, the same container's `octane:start`
  process picks up `--watch` on its own.

- Xdebug now defaults to `xdebug.mode=off` in the dev image, switched on
  per project with Xdebug's own `XDEBUG_MODE` env var in `.env`. It was
  `develop,debug` unconditionally: a loaded-and-active Xdebug slows every
  request and is documented as unsafe inside Swoole coroutines, and it was
  active for every project whether or not anyone was debugging. Raised
  from a real project's evaluation, comparing against Sail, which also
  installs Xdebug but defaults `XDEBUG_MODE` to `off`.

  Verified live, including the case that could have gone wrong -- php-fpm
  clears the environment for its workers, so it wasn't obvious an env var
  would reach them: with nothing set, `xdebug_info('mode')` was empty both
  from the CLI and from a request served through nginx + php-fpm; with
  `XDEBUG_MODE=debug` in `.env`, both reported `["debug"]` after a plain
  `ship up`, no rebuild.

- `ship.json`'s `publishPorts` (default `true`): `false` makes
  production publish nothing to the host. Raised from a real project's
  evaluation: it fronts its apps with a reverse proxy over a shared
  Docker network, but production still mapped `${APP_PORT:-8000}:8000`,
  and a Docker-published port bypasses host firewalls like `ufw` -- the
  app would have answered directly on the server's public IP, skipping
  the proxy's TLS and headers. A `docker-compose.override.yml` can't
  remove it cleanly either, since Compose merges `ports` lists rather
  than replacing them. An explicit option rather than implied by
  `externalNetwork` being set: the two are independent decisions (a
  shared network for reaching a database says nothing about whether
  the app itself should be reachable from outside), and an implicit
  coupling would be a surprise either way. Production only --
  development always publishes what it needs. `ship up`'s own
  "did the port actually bind" check needs no change: it reads
  `docker compose config`, so a service with no ports simply isn't
  checked.

  Verified live with `ship build`: with `publishPorts: false` it
  completed successfully, both containers showed only internal ports
  (no host mapping), and a request to the host's port 80 got no response.

- Fixed a real race in MySQL's healthcheck, found while live-testing
  `deployCommands`: `mysqladmin ping -h localhost` makes `mysqladmin` use
  the unix socket, and on a fresh volume the image first starts a
  *temporary* server that listens on that socket only (`port: 0`, no
  TCP), stops it, then starts the real one. Measured directly: the socket
  ping succeeded from ~9s to ~13s while TCP was still refusing
  connections, so the container went "healthy" a few seconds before
  anything could connect to it -- and whatever ran right after (a deploy
  command's migration here; `ship up`'s own healthcheck wait, or a
  `ship artisan migrate` typed right after it, for any project) hit
  "connection refused". Now pings `127.0.0.1`, which only succeeds once
  the real server is up. Measured both ways on a fresh container: at the
  instant the old check first reported healthy a TCP login was refused;
  at the instant the new one did, it succeeded. Checked Postgres for the
  same pattern rather than assuming: `pg_isready` over the socket never
  succeeded before TCP did, so it's left alone.

- `ship.json`'s `deployCommands`: shell commands meant to run exactly once
  per deploy, after the images are built and before any new container
  starts -- migrations, a package's own one-off setup, creating buckets.
  Raised from a real project's evaluation: its deploy script ran
  `migrate --force` and similar once, before starting the new containers,
  while ship's only production hook ran at every container *boot*
  (`FrameworkAdapter::releaseCommands()`: `artisan optimize`), which is
  exactly wrong for a migration -- with more than one container from the
  same image, each would run it at once. Deliberately named
  `deployCommands`, not `releaseCommands`, to not collide with that
  existing boot-time concept.

  Each runs in a one-off `docker compose run --rm --no-deps -T` container
  of the freshly built app image (`sh -c`, so any shell line works),
  after bringing up the services provisioned that aren't built from
  `ship/Dockerfile` (databases, caches) with `up -d --wait`, since nothing
  else would start them yet -- the app has no `depends_on` for them. If a
  command fails, the sequence stops there and starts nothing new, so a
  failed migration leaves the previous containers serving instead of new
  code booting against a schema it doesn't match. The docker argv building
  lives in `Ship\Docker\DeployPlan`, not in `UpCommand`, so anything else
  needing the same sequence can reuse it. This ran inside `ship up --prod`
  itself at the time -- superseded by `ship build`/`ship release --tag`
  (see that entry below), which instead writes the same sequence into the
  release's own `deploy-commands.sh` for the operator to run, since neither
  command talks to a production server directly.

  Verified live against a real production stack with MySQL: the command
  ran once against a database that was actually ready (this is what
  surfaced the MySQL healthcheck race above), and a stack whose middle
  command fails stopped there without starting the app or webserver.

- Octane runs as `www-data` in production (Swoole, RoadRunner, and
  FrankenPHP), instead of root. Raised from a real project's evaluation: the prod
  entrypoint's `chown www-data` step only helped php-fpm, whose *master*
  starts as root purely to drop each worker to `www-data` itself --
  Octane is its own long-lived server with no such split, so the whole
  process, every request handler included, ran as root. The generated
  entrypoint now drops to the user named by a `SHIP_RUN_AS` env var, when
  one is set, via `exec su-exec "$SHIP_RUN_AS" "$@"` before its plain
  `exec "$@"` fallback -- after the root-only steps (release commands, the
  chown of `storage/`, `bootstrap/cache/`, ...). Decided per container at
  runtime rather than baked into the script (an earlier version took it as
  a build-time parameter): the same image, and so the same script, backs
  containers that need opposite things -- a php-fpm `app` has to start as
  root, while a Horizon process running the same image must not. The
  generated compose file sets `SHIP_RUN_AS` on exactly the services that
  should drop. `su-exec` (unlike `su`/`sudo`) `exec()`s the target directly, so
  the server is still PID 1 and `docker stop`'s SIGTERM still reaches it.
  php-fpm keeps the plain `exec`: dropping its master would break the
  very drop-the-workers behavior it relies on.

  Originally excluded FrankenPHP: its Debian base needed its own non-root
  setup -- Caddy writes to `/data` and `/config` (root-owned in that
  image), and `su-exec` doesn't exist there. Closed once asked directly
  "what about FrankenPHP running as root": `Dockerfile.frankenphp` now
  chowns those two directories to `www-data` in the `prod` stage (a
  no-op cost otherwise), and the entrypoint's drop falls back to
  `setpriv` (from `util-linux`, already in the image) when `su-exec`
  isn't there -- confirmed live to `exec()` its target directly the same
  way `su-exec` does, not fork-and-wait. No capability needed for the
  low-port case either: ship already publishes the unprivileged
  `${APP_PORT:-8000}`, not 80/443.

  Verified live against a real Laravel + Octane + FrankenPHP production
  build: `octane:start` and the embedded `frankenphp` process were both
  `www-data` (checked via `/proc`, since `ps` isn't in this image), a
  real request returned 200, the Caddy directories were
  `www-data:www-data`, and `docker stop` took 1s with exit code 0
  (graceful, not the 10s SIGKILL fallback a wrapper process swallowing
  SIGTERM would have produced).

  Verified live against a real `laravel/laravel` + `laravel/octane`
  Swoole production build: `octane:start` was PID 1 owned by `www-data`
  along with the whole Swoole master/manager/worker tree, `GET /` returned
  200 (so the SQLite session write into the chowned `database/` worked
  as `www-data`), and `docker stop` took 3.7s -- graceful shutdown, not
  the 10s SIGKILL fallback a wrapper process swallowing SIGTERM would
  have produced.

  Re-verified after moving from a build-time parameter to the
  `SHIP_RUN_AS` runtime env var, in a real Swoole production build: the
  app's `octane:start` was still PID 1 owned by `www-data` and `GET /`
  returned 200. And the case the change exists for, in a php-fpm build:
  the `app`'s php-fpm master is still root (it has to be) with its pool
  workers as `www-data`, while a second container from the same image
  with `SHIP_RUN_AS` set ran as `www-data`.

- `ship.json`'s `processes`: a name => shell command map of extra
  long-running processes that run the app from the same image -- a queue
  worker, Horizon, the scheduler. Raised from a real project's
  evaluation: production ran only the Octane process, with nothing in
  the package mentioning queues or the scheduler, while the project ran
  Octane, Horizon and `schedule:run` under Supervisor inside one
  container. Each becomes its own compose service instead: independently
  restartable, visible in `docker compose ps`, each with its own SIGTERM,
  which a Supervisor-in-the-image setup can't give.

  Built from a copy of the app's own build config (same Dockerfile,
  target and args, so identical image content -- Docker's cache makes the
  repeat builds near-instant and `docker save` shares the layers) rather
  than a shared `image:` tag: an image-only service would try to pull a
  tag that only exists once the app has built, a race Compose doesn't
  order for you. They get the app's environment and networks (so the same
  database, and `externalNetwork`), no ports, `SHIP_RUN_AS=www-data`, and a
  60s `stop_grace_period` because Compose's default 10s SIGKILLs Horizon
  or a queue worker mid-job. A name that isn't a valid service name, or
  collides with one ship already generates, is rejected with a clear
  message instead of producing a broken compose file. Production only --
  in dev these are `ship artisan ...` commands you run yourself against the
  bind-mounted code.

  Verified live in two real production builds. Swoole: with a scheduler
  (`schedule:work`) and a queue worker (`queue:work`) configured, all three
  services ran as `www-data`, only the app published a port, and PID 1 in
  each process container was `php` itself -- `sh -c` exec'd the command
  rather than wrapping it, so SIGTERM reaches it -- and both stopped in
  about 0.4s with exit code 0. php-fpm, the case that needed
  `SHIP_RUN_AS` to be per-container: the app's master stayed root, its
  pool workers were `www-data`, and a `worker` process from the same image
  ran as `www-data`.

- `ship.json`'s `hostUser` (opt-in, dev only): run the dev app as the
  host's own UID/GID instead of root. Raised from a real project's
  evaluation as the one real downgrade from Sail on WSL2: `vendor/`,
  `public/build` and `storage/` ended up root-owned after any composer or
  build run inside the container. Earlier documented as a deliberate
  tradeoff rather than changed by default, and that reasoning stands --
  but the reviewer's point was fair that its objection (a *fixed*
  container UID only works when it matches the bind mount's) doesn't apply
  when the UID comes *from* the host. Opt-in keeps the default exactly as
  it was.

  Three coordinated pieces, all generated together so they can't
  disagree: the dev image is built with `HOST_UID`/`HOST_GID` (a matching
  user, or an existing one reused -- a host GID like macOS's 20 already
  exists in Alpine as `dialout`, and only the numeric ids matter for file
  ownership); the dev entrypoint reads `SHIP_HOST_USER` to run `composer
  install` and any non-php-fpm command (an Octane server) as that user,
  leaving php-fpm's master root so it can drop its own workers; and the
  `ship exec`-family commands add `--user` for the app service only.
  That last one learns what to do from an `x-ship` marker in the
  *generated compose file*, not by re-reading `ship.json`, so a
  production file, or one regenerated after the option was turned off,
  can never get a stray `--user`. `docker exec` sets `HOME` from the
  user's passwd entry, so Composer's cache lands somewhere writable.

  When it can't apply -- native Windows or already root (no non-root POSIX
  user to match), or `SHIP_MUTAGEN` (it syncs into a volume as root) --
  `ship up` says so instead of quietly running as root, since the visible
  result of that (the root-owned `vendor/` they turned it on to avoid)
  would otherwise be a mystery. FrankenPHP needed its own `useradd`-based
  setup (Debian, not Alpine) rather than being excluded outright -- see
  the dedicated entry below.

  Verified live on a real Linux filesystem (WSL2, UID 1000), not just
  unit tests: the boot-time `composer install` left `composer.lock` and
  `vendor/` owned by `1000:1000` on the host; php-fpm's master stayed root
  with its pool workers as the host user, and a real request's file write
  landed `1000:1000`; and `ship composer require` -- the `exec --user`
  path, the reviewer's actual complaint -- left `composer.json`,
  `composer.lock` and the new `vendor/` package owned by `1000:1000`
  too. `ship shell` reported uid/gid 1000 with `HOME=/home/ship`.

- Fixed a real bug found while live-testing the three FrankenPHP items
  above, unrelated to any of them: the `dunglas/frankenphp` base image
  has neither `ext-zip` nor an `unzip`/`7z` binary, so Composer can't
  extract a single package distributed as a zip -- the normal case for
  anything pulled from Packagist, not an edge case. This failed the
  `builder` stage outright on the very first real `composer.lock`
  (confirmed with a plain `composer require laravel/octane`), meaning
  production FrankenPHP builds with any real dependencies were already
  broken before today. Fixed with `apt-get install unzip` in the `base`
  stage. The main Dockerfile doesn't need this -- Alpine's
  `php:*-fpm-alpine` base already ships `unzip`.

- Replaced `ship up --prod` with two new commands, `ship build` and
  `ship release --tag <tag>` -- a deliberate breaking change, not an
  addition alongside the old path. Raised from a real need: a developer
  with server access, a DevOps team, and CI/CD all needed to hand off
  *one* portable artifact a destination server can run with nothing but
  Docker installed -- no PHP, no source tree, no project Dockerfiles, and
  no `.env` committed anywhere -- rather than each needing its own
  variant of "build the image, then figure out how to get it running."
  `ship up --prod` could build the image, but starting it anywhere else
  meant shipping the whole project (source, `ship/Dockerfile`, `ship.json`)
  to rebuild from scratch there, exactly what this exists to avoid.

  `ship build` builds every project-owned production image (anything with
  a `build:` in the generated compose file -- the same predicate
  `DeployPlan::infrastructureServices()` already used, inverted) under a
  fixed local tag (`<name>-<service>:local`); `ship release --tag` runs
  the exact same planning and build step with the real tag instead, then
  assembles `dist/ship/<tag>/`: a compose file with `build:` stripped and
  `image:` tags pointing at what was just built, `.env.production` copied
  to `.env`, each unique image exported with `docker save`, and
  `release.json` metadata (tag, git commit, ship version, images -- never
  a secret). One shared class, `Ship\Docker\ProductionBuildRunner`, backs
  both commands, so there's exactly one place that decides what a
  production image is.

  `ship.json`'s `name` (new, optional, defaults to the project directory's
  basename -- what Compose's own implicit naming already did) is the
  project name image tags use, since relying on an implicit directory-name
  default across a dev machine, CI runner, and a server risked three
  different tags for the same project. `ProductionImagePlan` groups
  services sharing an identical `build:` (most commonly "app" and every
  `processes` entry, which already copy "app"'s own build config) under
  one tag and one exported `.tar`, rather than building and saving the
  same content three times.

  `deployCommands` moves with it: `ship up --prod` used to run them
  itself; now `ship release --tag` writes the same sequence into the
  release's own `deploy-commands.sh` (bringing up the database/cache
  services first, then each command in a one-off container), for the
  operator to run on the server once the images are loaded -- `ship`
  itself still never deploys anywhere.

  A real bug found live, not in review: the first non-interactive `--tag`
  check only looked at `$input->isInteractive()`, which Symfony only ever
  sets false from an explicit `--no-interaction`/`-n` flag, never from
  detecting a non-tty stdin on its own. Piping from `/dev/null` with no
  `-n` -- exactly a plain CI `run:` step, no flag added -- left `ship
  release` printing "Release tag:" and hanging forever instead of failing
  fast. Fixed by also checking `stream_isatty(STDIN)` directly (unlike
  `posix_isatty`, available on every platform this package already
  claims to run).

  A second real bug found live: `deploy-commands.sh`'s own "bring up
  infrastructure first" step initially read the *release's* final
  `docker-compose.yml` to decide what counts as infrastructure -- but
  that file has already had `build:` stripped from every project-owned
  service by the time it's written, so "no `build:` key" (the same test
  `DeployPlan::infrastructureServices()` uses) misclassified "app" and
  "webserver" as infrastructure too, meaning the script would have
  started them *before* running migrations, defeating the entire reason
  the ordering exists. Fixed by reading `ship/docker-compose.generated.yml`
  instead -- `ProductionBuildRunner`'s own working copy, still carrying
  `build:` at that point.

  Verified live end-to-end against a real `laravel/laravel` + MySQL +
  Redis fixture, not just unit tests: `ship build` produced
  `<name>-app:local`/`<name>-webserver:local`; `ship release --tag 1.0.0`
  produced a `dist/ship/1.0.0/` with every file the spec calls for, a
  compose file with no `build:` anywhere in it; `docker load`-ing both
  tars and running `docker compose up -d` from inside that directory
  alone (no `-f` pointing anywhere else, no source tree present) booted
  the real stack, and `php artisan migrate --force` against it followed
  by a real HTTP request both succeeded. Re-verified with `deployCommands`
  set and a custom `ship.json` `name`: the generated `deploy-commands.sh`
  correctly listed only `mysql redis` (not `app`/`webserver`) after the
  second bug above was fixed, and the custom name was reflected in every
  image tag.

- Fixed a real, package-wide data-loss bug: every stateful service
  (MySQL, Postgres, Redis, Garage, Meilisearch, RustFS, SeaweedFS, Silo)
  only ever attached its named data volume in development --
  `$environment->isDevelopment() ? [...] : []`, present unchanged since
  the very first commit, with no comment ever explaining it as
  deliberate (unlike every other intentional tradeoff in this codebase).
  In production every one of those containers ran with no volume at
  all, so the data lived only in the container's own writable layer --
  a `docker compose restart`, a redeploy, or the host rebooting wiped
  it. Harmless while `ship up --prod` was just a smoke-test habit
  nobody ran for more than a few minutes; a real, consequential bug now
  that `ship release` produces an artifact meant to run unattended on a
  server. Raised directly when asked for the state of the package.
  Fixed by making every one of those volumes unconditional. Redis also
  had `--save ""` (RDB snapshots disabled) in production specifically --
  also unconditional now: ship only ever wires Redis as a cache/session
  store, never a queue, but losing every session on each redeploy logs
  out every active user, which is a real, user-visible regression, not
  a theoretical one. The README's own "Backing up data volumes" section
  already described the *intended* (persist-always) behavior, not what
  the code actually did -- now the two agree, no doc change needed.

  Verified live, not just the 6 unit tests this broke (each of them had
  asserted the old, now-wrong behavior directly, e.g.
  `test_data_volume_only_exists_in_development`): built a real
  production MySQL container via `ship build`, wrote a row, fully
  removed and recreated the container (`docker compose rm -f && up -d`,
  not just a restart -- confirming the *container*, not merely the
  process, is disposable now), and read the same row back afterward.

- Fixed the FrankenPHP healthcheck known gap: `dunglas/frankenphp`'s base image bakes in a
  HEALTHCHECK against Caddy's own admin metrics endpoint (`curl localhost:2019/metrics`), which
  this project's `prod` stage inherited unexamined. Investigated rather than assumed: Octane's own
  `StartFrankenPhpCommand` really does run the real `frankenphp` binary against a real Caddyfile
  with that admin endpoint genuinely configured, so the healthcheck isn't pointed at nothing, the
  way "Octane manages this through an embedded extension" might suggest on a first read. The real
  gap is narrower but still real: `/metrics` reports whether Caddy's own HTTP server process is
  alive -- a property of the control plane, not of whatever the embedded PHP worker is actually
  doing with each request. Fixed with `HEALTHCHECK NONE` in the `prod` stage, overriding the
  inherited one entirely -- bringing FrankenPHP in line with Swoole/RoadRunner, which have never
  had a healthcheck of any kind (their `php:*-fpm-alpine` base ships none), rather than inventing a
  new, different one. No Octane runtime loses a feature its siblings already had; FrankenPHP loses
  a false sense of one it had by accident of its base image alone.

  Verified live, not just reasoned about: built a real production FrankenPHP image and ran it
  directly, with no real app config (deliberately, to force every request to fail) -- a plain
  request returned 500 on every try, while `curl localhost:2019/metrics` *inside the same
  container* returned 200 throughout, proving the exact false-positive the old healthcheck was
  exposed to: it would have read "healthy" the entire time the app was completely broken. After
  the fix, `docker inspect`'s `.State.Health` is `nil` and `docker ps` shows a plain "Up", with no
  `(healthy)`/`(unhealthy)` qualifier at all -- the same no-healthcheck shape Swoole/RoadRunner
  already have.

- Fixed a real credentials bug: Garage, RustFS, and Silo all hardcoded their admin
  credentials to the literal strings `"ship"`/`"shipsecret"`, with no way to override them --
  unlike every other credentialed service in the registry (MySqlService's `DB_PASSWORD`,
  PostgresService's own, ...), which all use a `${VAR:-default}` expression a project's own
  `.env`/`.env.production` can override. Raised directly when asked for the state of the package.
  Matters most for Silo specifically: its admin console is published to the host by default
  (`publishPorts` defaults to `true`), so a hardcoded literal meant every default `ship release`
  deploy exposed a login page with a publicly-known credential on the open internet. Garage/RustFS
  don't publish a console, so they were lower-risk, but still shared one hardcoded credential
  across every project that ever selected them.

  Fixed by sourcing all three from the same `{$prefix}AWS_ACCESS_KEY_ID`/
  `{$prefix}AWS_SECRET_ACCESS_KEY` expression `environmentVariables()` already exposes to the app
  (Garage's own default bucket too, from `{$prefix}AWS_BUCKET`) -- one value now drives both what
  the app connects with and what the storage container actually provisions, the same "one value,
  two roles" pattern MySQL/Postgres already use for `DB_PASSWORD`. A project that never sets these
  still gets the same defaults as before; one that does gets a real credential instead of a
  publicly-known one.

  Verified live: brought up a real Silo container with no override and confirmed its actual
  `MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD` env vars still resolved to the old defaults
  (`ship`/`shipsecret`, so nothing broke for an untouched project); then added
  `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` to `.env` and recreated the container, confirming
  those same env vars now read the overridden values instead.

- Fixed a real secret leak found via an independent audit: `ship init`'s own `.dockerignore`
  entries (`.env`, `.env.*`) only ever match at the build context *root* -- confirmed live, not
  assumed, by building a minimal image with ship's exact file. `dist/ship/<tag>/.env` (`ship
  release`'s own copy of `.env.production`) was never excluded, and the builder stage's `COPY . .`
  picked it straight up, landing readable inside the very next image built in that project --
  carrying every earlier release's own tars forward too. Fixed with `**`-prefixed patterns
  (`**/.env`, `**/.env.*`, `!**/.env.example`), which match at any depth, plus an outright `/dist`
  exclusion. Verified live: the same minimal-image test, with the fixed patterns, excluded both the
  nested `.env` and the whole `dist/` tree, while still preserving a root `.env.example`.

- Fixed another real bug found via the same independent audit: `MailpitService`/`DuskService`
  never checked `$environment` at all, so production got a Mailpit and a Selenium container too
  whenever selected -- dev/test-only tooling with no business in a production release. Worse than
  just unwanted containers: Mailpit's `MAIL_HOST` unconditionally overrode whatever real mail
  config `.env.production` actually set (`environment:` always wins over `env_file:`), so real
  mail -- including password reset links -- was silently captured into an unauthenticated web UI
  instead of ever being sent.

  Fixed generically, not with two one-off special cases: an empty `composeFragment()` is now a
  service's own signal to `ComposeFileBuilder::applyService()` that it contributes nothing at all
  in this environment -- no compose service, no env vars injected into `app`, no `removes()`
  either. Any third-party `ServiceDefinition` gets the same opt-out for free. Every built-in
  service's fragment was already never empty, so this is fully backward compatible.

- Fixed a third real bug found via the same independent audit, the most severe of the three:
  re-running `ship init` rebuilt `ShipConfig` from only the four fields its own prompts ever touch
  (`php`, `node`, `services`, `additionalServices`), silently discarding every other field --
  `extensions`, `serviceNames`, `externalNetwork`, `phpExtensions`, `publishPorts`,
  `deployCommands`, `processes`, `hostUser`, `name`. The README called re-running "always safe",
  and `ship up` itself tells users to do exactly that after a stub-version mismatch. Losing
  `publishPorts: false` alone silently re-exposes ports a project turned off deliberately; losing
  `hostUser: true` silently goes back to root-owned files; losing `deployCommands` silently stops
  migrations from running on the next deploy -- all without any error, warning, or diff to notice.

  Fixed by reading the existing `ship.json` (if any) before writing the new one, and carrying its
  hand-edited-only fields forward unchanged -- `null` (not an exception) when there's nothing to
  preserve yet (a first-ever `ship init`), or the existing file is malformed, since a broken
  `ship.json` is exactly what re-running `ship init` might be trying to fix in the first place.

- Fixed `ship release` reporting success when `docker save` fails (an independent audit catch):
  `exportImages()`'s own exit code was ignored entirely, so a release could claim "release ready"
  with a missing or truncated tar in it -- disk full, a bad tag, a docker daemon hiccup, anything
  `docker save` itself would have failed loudly for on its own. Fixed to stop immediately on the
  first failure, without writing `release.json`/`deploy-commands.sh` against an incomplete
  `images/` directory.

- Fixed three real Reverb bugs found via the same independent audit, all from the same root
  cause: `ReverbService`'s own `composeFragment()` has no access to `$appEnv`, `$config`, or
  `$hostUser`, so it could never align itself with `app` on its own. Reverb never got `app`'s own
  injected environment (`DB_*`, `REDIS_*`, ...), so anything it touched that needed the database
  (a private-channel auth callback, say) failed to connect; it never got `SHIP_RUN_AS`, so it ran
  as root in production, the same gap already fixed for every Octane runtime and `processes`
  entry; and its own build args were missing `OCTANE_RUNTIME`/`HOST_UID`/`HOST_GID`, forcing a
  second, wasteful image build for content that should be identical to `app`'s.

  Fixed in `ComposeFileBuilder`, after `app`'s own environment and build args are already final --
  only the build *args* are copied, not the whole build block, so FrankenPHP overriding `app`'s own
  dockerfile never leaks onto Reverb, which never needs Caddy's image just to run a plain `php
  artisan reverb:start`.

- Fixed two real nginx bugs found via the same independent audit. `location ~ \.php$` passed *any*
  request path ending in `.php` to PHP-FPM, existing file or not -- restricted to an exact
  `location = /index.php` match, the only path that block should ever see; a `.php` file somehow
  ending up under `public/` some other way now gets served statically by `location /`'s
  `try_files` instead of executed. Separately, the upstream host was hardcoded to `app:9000` --
  renaming the app service via `ship.json`'s `serviceNames` left nginx 502ing every request
  against a DNS name nothing in the stack answers to anymore. `ship init` now substitutes the
  resolved app service name into the published config when it differs from `app` -- a no-op for
  the overwhelming majority of projects that never touch `serviceNames`.

- Fixed a real credentials bug found via the same independent audit: `${DB_PASSWORD:-secret}`,
  `shipsearchkey`, and `ship`/`shipsecret` all used the exact same `${VAR:-default}` expression in
  both environments, so a `.env.production` that forgot (or never had) a real value silently
  shared the same publicly-known default every such project would -- with no error, warning, or
  way to notice short of reading the generated compose file by hand.

  Added `Ship\Docker\RequiredEnv::expr()`, applied to MySQL/Postgres's `DB_PASSWORD`,
  Meilisearch's master key, and Garage/RustFS/Silo's secret access key -- never the access key id
  itself, or `DB_USERNAME`: access-key/username-shaped values stay plain defaults, same as
  everywhere else. A friendly `${VAR:-default}` in development, but `${VAR:?...}` in production,
  verified live against a minimal compose file both ways: `docker compose` itself refuses to run
  at all when the real value was never set, with a clear, actionable error naming the variable.
  Matters most for Silo, whose admin console is published to the host by default -- a missed
  `.env.production` value there would otherwise expose a login page with a publicly-known
  credential on the open internet.

  The app-facing side (`DB_PASSWORD`, etc. in `environmentVariables()`) deliberately keeps its
  plain default rather than gaining the same check: that method has no `$environment` parameter,
  and doesn't need one here -- Compose interpolates an entire generated file as one pass, so a
  single `:?` anywhere in it aborts the whole command before any service, the app included, ever
  starts.

- Gave SeaweedFS real S3 authentication -- found via the same independent audit, confirmed live:
  the base image's S3 gateway has no authentication at all unless handed an identity config file
  via `-s3.config`. An unsigned, credential-less request against a plain `weed server -s3`
  returned 200 with a real bucket listing. There's no env-var-driven auth option, only a config
  *file*, which the real secret (known only once `.env.production` exists, long after `ship init`
  published anything) can't be baked into ahead of time. Fixed by overriding the image's own
  ENTRYPOINT with a shell that generates that file from the already-injected
  `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` env vars at container *boot*, then execs the real
  server against it.

  Hit the same bug class the audit separately flagged for `ship.json`'s own `processes` commands
  while implementing this: a bare `$VAR` in a compose `command:` string gets interpolated by
  Compose itself (against the host's own environment, empty) before the container's shell ever
  runs it -- confirmed live by inspecting `docker compose config`'s actual resolved output, which
  showed both credentials blank. Fixed with `$$`, the same escape Compose's own docs call for.

  Verified live twice: once showing the *before* state (unsigned request -> 200, real bucket
  listing) to prove the bug concretely rather than just reasoning about it, and once after the fix
  (through the real `ship up`-generated compose file, not a hand-rolled one) showing the identity
  file with real credentials and the same unsigned request now returning 403.

- Gave Garage a real RPC secret and admin token -- found via the same independent audit: the
  published `garage.toml` stub hardcoded an all-zero `rpc_secret` shared by every project that
  ever selected Garage, and the admin API (bucket/key management, reachable by anything else on
  the "ship" network) had no token at all. Garage's own CLI reads both `GARAGE_RPC_SECRET` and
  `GARAGE_ADMIN_TOKEN` as env vars directly, explicitly documented to override `config.toml`, so no
  wrapper script or generated file was needed the way SeaweedFS's identity config required --
  removed `rpc_secret` from `garage.toml` entirely and set both through the usual env var
  mechanism, required (not just overridable) in production via `RequiredEnv`.

  While verifying this live, found and fixed two more real, pre-existing bugs blocking Garage from
  booting at all, unrelated to the audit: this image's own `--default-access-key` validates
  minimum lengths and refuses to start otherwise -- `"ship"` (4 chars) failed an 8-character
  minimum, and once that was raised, `"shipsecret"` (10 chars) failed a separate 16-character
  minimum for the secret. Both defaults are now long enough.

  Verified live end-to-end through the real `ship up`-generated compose file: the container now
  reports healthy (the healthcheck's own `garage status` call picks up `GARAGE_RPC_SECRET` from
  the container's environment automatically), an unsigned admin API request gets a 403 with
  "Bearer token must be provided" where it previously had no gate at all, the correct token gets a
  200, and a wrong one gets a 403.

- Fixed Garage being misclassified as application code in deploy ordering -- found via the same
  independent audit: `DeployPlan::infrastructureServices()` used "has a `build:` at all" as a
  proxy for "is this the app's own code," correct for `app`/`webserver`/`reverb`/`processes`
  (which all build from `ship/Dockerfile`), but it also caught Garage, a project-owned service
  with its own small packaging Dockerfile for unrelated reasons. A "create the bucket" deploy
  command would run against a Garage that `deploy-commands.sh`'s own "bring up infrastructure
  first" step never started. Fixed by checking the build's own dockerfile path instead of just
  whether `build:` is present at all: only `ship/Dockerfile`/`ship/Dockerfile.frankenphp` count as
  application code that must not start until deploy commands succeed -- everything else, Garage
  included, is infrastructure.

- Fixed `docker-compose.override.yml` leaking into production builds -- found via the same
  independent audit: the override file is a dev convenience (see `ComposeCommand::baseArgs()`'s
  own docblock), but `ProductionBuildRunner`'s production build picked it up unconditionally like
  every other caller. A project's dev-only `build:` customization silently leaked into the
  production image, and the release's own exported `docker-compose.yml` (built from
  `ship/docker-compose.generated.yml` alone) never matched what was actually built as a result.

  Fixed with a new `includeOverride` parameter on `baseArgs()`, defaulting to `true` (every
  existing caller unaffected) except `ProductionBuildRunner`'s own call, which now passes `false`.
  Omitting it from the build entirely fixes both problems at once: nothing to leak, and nothing
  for the artifact to disagree with.

- Fixed `ship up`'s own healthcheck wait being shorter than some services' own healthcheck allows
  -- found via the same independent audit. The wait was a flat "15 attempts x 2s = ~30s" budget,
  well past MySQL/Postgres/Redis's own interval x retries (5s x 5 = 25s) when that comment was
  written, but Garage/RustFS/Silo's own healthcheck (10s `start_period` + 5s x 10 retries = 60s)
  and SeaweedFS's (10s x 5 = 50s) can both legitimately still be "starting" well after this gave
  up, producing a false "never became healthy" on a slow first boot (a fresh volume's own
  initialization) rather than a real problem.

  Computed from the service's own generated `healthcheck:` block instead (`start_period` +
  `interval` x `retries`, plus a small buffer), so the wait is never shorter than Docker's own
  patience for it, whatever a service's healthcheck happens to be configured with.

- Validated `ship.json`'s `php`/`node` versions and service names -- found via the same
  independent audit: `"php": 8.4` (a bare JSON number) previously reached the constructor's own
  strict `string $phpVersion` type unchecked, surfacing as a raw TypeError instead of a message
  naming `ship.json` at all. `serviceNames`/`additionalServices` names also went straight into
  generated compose keys (and, for `additionalServices`, an env var prefix) with no validation,
  unlike `processes` names, which already get exactly this check.

  Fixed with a single `validate()` step in `ShipConfig::fromFile()`: `php`/`node` must be strings,
  `serviceNames` values reuse `processes`'s own regex (a compose key only, so a hyphen is fine),
  and `additionalServices` names reuse `ship init`'s own interactive prompt validation (also an
  env var prefix, so no hyphen).

- Escaped `$` in `ship.json`'s `processes` commands -- found via the same independent audit, the
  same bug class fixing SeaweedFS's own identity config hit earlier this session: Compose
  interpolates a bare `$VAR` in a command string itself (against the host's own environment, not
  the container's) the same way it does `${VAR}`. A `processes` command referencing a real shell
  variable (e.g. `"php artisan queue:work --queue=$QUEUE"`) had it silently blanked out before the
  container's shell ever ran it. A project writing a `processes` entry expects to write a plain
  shell command, not a Compose-interpolated string, so every literal `$` is now escaped to `$$`
  automatically rather than asking every entry to know Compose's own syntax.

- Fail clearly when `DB_USERNAME=root` would crash MySQL outright -- found via the same
  independent audit, confirmed against the official image's own documented behavior: `mysql`'s
  entrypoint refuses to start at all when `MYSQL_USER=root`, crashing the whole container.
  `DB_USERNAME=root` is a real value to find in a project's own `.env` -- Laravel's own stock
  default for years before the framework's sqlite-first skeleton.

  ship can't catch this inside `ComposeFileBuilder` itself -- `MYSQL_USER` is set to the Compose
  expression `${DB_USERNAME:-app}`, never the actual resolved value, which only exists once a
  real `.env`/`.env.production` is read. Added `Ship\Docker\MySqlUsernameGuard`, checked in
  `UpCommand` (reading `.env`, since `ship up` actually starts the container) and `ReleaseCommand`
  (reading `.env.production`, since `ship` itself is never present later to catch this once a
  release ships to a server with no `ship` installed at all).

  Verified live: the official `mysql:9.7` image really does crash with exactly that error given
  `MYSQL_USER=root`; `ship up` with `DB_USERNAME=root` in `.env` now fails immediately with a
  specific, actionable message instead of attempting to boot at all; and a legitimate username
  proceeds normally.

- Stopped `ship build`/`ship release` from overwriting the dev compose file -- found via the same
  independent audit: `ProductionBuildRunner` wrote to the exact same
  `ship/docker-compose.generated.yml` file `ship up` itself generates and every dev command
  (`ship exec`, `ship shell`, `ship composer`, `ship npm`) reads. Running either production command
  after `ship up` silently left the dev compose file overwritten with a production one until the
  next `ship up` regenerated it -- during which those commands would all target the wrong
  environment, `hostUser`'s `--user` flag included, since production never sets the `x-ship`
  marker.

  Fixed with a new `ComposeCommand::PRODUCTION_COMPOSE_FILE` constant
  (`ship/docker-compose.production.yml`) and an optional `$composeFile` parameter on
  `baseArgs()`, defaulting to the dev file so every existing caller is unaffected.
  `ProductionBuildRunner` now writes and reads its own file explicitly; `ReleaseCommand`'s two
  reads follow it too.

  Verified live: after `ship up`, `ship build` now produces a *separate*
  `ship/docker-compose.production.yml` (`target: prod`/`prod-nginx`) while
  `ship/docker-compose.generated.yml` stays byte-for-byte the dev one (`target: dev`/`dev-nginx`),
  and `ship exec app php -v` immediately afterward still correctly reports the running dev
  container (Xdebug installed, confirming it's the dev image).

- Extension classes were constructed twice per run, and their warnings printed twice on `ship
  up`/`build`/`release` -- found via the same independent audit. `ExtensionLoader::load()` and a
  separate `loadFrameworkAdapters()` each independently instantiated every class listed in
  `ship.json`'s `extensions` array, so any extension implementing `FrameworkAdapter` was built
  once by each method within the same `Application` boot, and `ProductionBuildRunner`'s own
  `detectFrameworkAdapters()` built it a third time on `ship build`/`ship release`. A third-party
  extension whose constructor has any side effect (logging, a network call, anything) would have
  run it that many times per invocation.

  `load()` now resolves warnings and `FrameworkAdapter` instances in a single pass, returning
  both; `loadFrameworkAdapters()` is gone. `ProductionBuildRunner::build()` takes the
  already-resolved list from its caller instead of re-instantiating every class itself.

  Separately, `Application`'s constructor always printed every warning to STDERR, and
  `UpCommand`/`BuildCommand`/`ReleaseCommand` each loaded the extensions again for their own
  fresh `ServiceRegistry` and printed the same warning a second time via `$output`. Those three
  commands no longer re-print what `Application` already surfaced once.

  Verified live: a `ship.json` extension class that doesn't exist now prints exactly one
  `ship: warning: ...` line on both `ship up` and `ship build`, not two.

- Pinned every floating build input in the generated Dockerfiles -- found via the same
  independent audit: `install-php-extensions` was fetched from `/latest/download/` with nothing
  verifying the response, `composer:2`/`nginx:alpine`/`selenium/standalone-chrome:4` all track a
  moving tag, and `pecl install redis`/`swoole` plus `install-php-extensions xdebug` took no
  version at all -- any of these can silently change what a build produces weeks apart with no
  corresponding change to this repo.

  `install-php-extensions` is now pinned to a specific release tag with its SHA-256 checksum
  verified before it's ever executed; `composer`, `nginx`, and the Selenium image are pinned to a
  specific version; redis/swoole/xdebug each take an explicit `ARG *_EXTENSION_VERSION`.

  Also stopped `npm ci || npm install` from silently masking `npm ci`'s own lockfile-drift
  failure by falling back to `npm install` (which resolves and writes a *new* lockfile instead of
  failing) -- `npm install` is now only the fallback when no `package-lock.json` exists at all.

  Verified live: a full `ship build` (Octane Swoole + Dusk + a `phpExtensions` entry, exercising
  the checksum-verified `install-php-extensions` path, both pinned PECL installs, the pinned
  composer copy, and the `npm ci` branch) completes successfully; the pinned nginx tag resolves
  and pulls.

- Warn when `deploy-commands.sh`'s executable bit can't actually be set -- found via the same
  independent audit: `chmod()` on it is a real, working executable-bit set on Linux/macOS, but a
  silent no-op on Windows, since NTFS has no Unix executable bit for it to set at all. `ship
  release` has no requirement to run on Linux/macOS specifically, so a release built on a Windows
  dev machine shipped a `deploy-commands.sh` that was never actually executable once copied to
  the server, with nothing telling the operator why `./deploy-commands.sh` would fail there.

  `ReleaseCommand` now warns explicitly when `deployCommands` is set and `PHP_OS_FAMILY` is
  Windows, pointing at the `chmod +x`/`sh deploy-commands.sh` workarounds instead of claiming an
  executable bit that was never actually set.

- Publish Garage's stub for an additional-instance-only selection too, and fix its hardcoded RPC
  address -- found via the same independent audit. `publishStubs()` only ever checked the default
  storage pick, so Garage selected *only* as a named `additionalServices` instance never got its
  stub files published at all -- that instance's own `build: {context: ./ship/garage}` had
  nothing to build from. Now also checks `additionalServices` for a `"garage"` entry.

  Separately, `garage.toml`'s `rpc_public_addr` was hardcoded to the literal `"garage:3901"`,
  correct for the default instance but wrong for any named instance, whose real compose service
  name is never just `"garage"`. `dxflrs/garage:v2.4.1` is FROM scratch with no shell, so the
  substitution can't happen at container boot the way SeaweedFS's identity file does -- the
  Dockerfile now has a separate "config" stage (alpine, which has a shell) that substitutes a
  `GARAGE_RPC_PUBLIC_ADDR` build arg into the file at build time, and only the already-substituted
  file is copied into the real, shell-less final stage. `GarageService::composeFragment()` sets
  that arg to the instance's own compose service name.

  Verified live: with Garage selected only as an additional "archive" storage instance, `ship
  init` now publishes `ship/garage/garage.toml`; building the Dockerfile with `--build-arg
  GARAGE_RPC_PUBLIC_ADDR=garage-archive:3901` bakes `rpc_public_addr = "garage-archive:3901"`
  into the image, and the container starts cleanly with it.

- Switch Reverb to the synced named volume when Mutagen is active -- found via the same
  independent audit: `ReverbService`'s own `composeFragment()` has no access to `$mutagenSync`,
  so its dev volume was always the raw bind mount (`.:/var/www/html`), even when `SHIP_MUTAGEN`
  is active and "app"/"webserver" both switch to the synced named volume instead. Reverb kept
  bind-mounting the unsynced host tree, reading stale code "app" itself no longer saw once
  Mutagen's sync caught up.

  `alignReverbWithApp()` -- already the one place that backfills what `ReverbService` can't
  compute on its own (env vars, `SHIP_RUN_AS`, build args) -- now also overrides Reverb's
  `volumes` to the same `ship-app-sync` named volume "app"/"webserver" use, whenever Mutagen is
  active.

- Collapse consecutive separators so `ProjectName` always produces a valid tag -- found via the
  same independent audit, confirmed live (`docker build -t foo..bar-app:local` fails outright with
  "invalid reference format"). Docker's actual grammar for an image name component is
  `[a-z0-9]+((\.|_|__|-+)[a-z0-9]+)*` -- a single separator between two alphanumeric runs, never
  two or more literal dots/underscores in a row. `ProjectName::sanitize()` only ever touched
  characters outside `[a-z0-9._-]`, so an input like `"foo..bar"` (already entirely within that
  set) passed straight through and still broke `docker build`/`docker save` later with the exact
  opaque error this class exists to avoid.

  A second pass now collapses any run of two or more separator characters, whatever mix of `.`,
  `_`, `-` produced it, down to a single `-` -- satisfying Docker's grammar unconditionally
  instead of special-casing every separator combination it allows.

- Stopped `runQuiet()`'s timeout from crashing `ship` with a raw stack trace -- found via the same
  independent audit. A command that actually hits its hardcoded 30s timeout (`mutagen sync
  create` scanning a large project tree, say) threw `ProcessTimedOutException` straight out of
  it, uncaught, crashing the whole process instead of failing with a friendly error. Every
  existing caller already treats an empty `runQuiet()` result as "the thing isn't there"/"that
  didn't work", so a timeout now degrades to that exact same signal.

  `runQuiet()` also takes an optional per-call timeout now, so a caller expecting something
  genuinely slower isn't stuck with the same 30s budget as `docker compose version`.
  `MutagenSync`'s own `mutagen sync create` call -- the audit's example -- uses a 60s budget for
  exactly that reason.

- Hardened `.github/workflows/ci.yml` -- found via the same independent audit. No top-level
  `permissions:` block meant this workflow ran with whatever the repo-wide default token
  permissions happened to be, never scoped down to what it actually uses (read-only checkout, no
  pushes/releases/PR comments); added `permissions: {contents: read}`.

  `actions/checkout@v4` and `shivammathur/setup-php@v2` were pinned by a mutable tag, not a
  commit -- either repository's maintainer (or anyone who compromised their account) could point
  that tag at different code without this repo ever changing. Both are now pinned to the exact
  commit the tag currently resolves to, with a version comment for readability.

  The Mutagen release tarball was fetched over plain HTTPS with nothing verifying the response
  was actually what Mutagen's maintainer published. Now downloads and checks against the
  release's own published `SHA256SUMS` file -- not a hardcoded hash that would go stale the
  moment `MUTAGEN_VERSION` is bumped.

  Verified live: the checksum step actually verifies the real v0.18.1 tarball successfully; the
  edited workflow file still parses as valid YAML.

- Housekeeping from the same independent audit: `Application`'s version was hardcoded to the
  literal `"0.1.0-dev"` forever instead of using `ShipVersion::current()` (Composer's own
  `InstalledVersions`), which already exists for exactly this purpose -- `ship --version` never
  reflected whatever was actually installed. Falls back to the same literal when
  `InstalledVersions` can't answer at all (running from a git checkout with no Composer
  metadata).

  `ShellCommand`'s docblocks referenced a nonexistent `ship.json` field (`"appName"` -- the real
  field is `serviceNames['app']`) and a nonexistent `Application` method
  (`"readExtensionClassesIfConfigured()"` -- the real method is `readConfigIfPresent()`).

  Deleted `stubs/docker/nginx/Dockerfile`: confirmed via grep to be referenced nowhere -- nginx
  now builds through `ship/Dockerfile`'s own `dev-nginx`/`prod-nginx` targets instead, and only
  `default.conf` from this directory is ever published.

  (The `composer.lock` sub-point from the same audit item is moot: this library doesn't commit a
  lock file at all.)

## Not started

- Cross-service coordination beyond what `ComposeFileBuilder::build()`
  already computes generically (`APP_URL`, `PHP_VERSION` backfill). A
  service that needs to know about *other* selected services beyond
  that has no clean mechanism yet — document the specific need if one
  comes up, rather than speculatively designing for it now.
