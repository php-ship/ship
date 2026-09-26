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

## Not started

- Cross-service coordination beyond what `ComposeFileBuilder::build()`
  already computes generically (`APP_URL`, `PHP_VERSION` backfill). A
  service that needs to know about *other* selected services beyond
  that has no clean mechanism yet — document the specific need if one
  comes up, rather than speculatively designing for it now.
