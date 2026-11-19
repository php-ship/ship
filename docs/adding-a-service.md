# Adding a service

Every optional piece of the stack — a database, a cache, a storage backend,
a runtime — is a class implementing `Ship\Contracts\ServiceDefinition`.
`RedisService` is the simplest complete example to copy from.

## Steps

1. **Create the class** in `src/Services/`, implementing all five methods:
   - `key()` — the string used in `ship.json` (e.g. `"redis"`)
   - `label()` — shown in `ship init`'s interactive picker
   - `group()` — which single-select group it belongs to (`database`,
     `cache`, `runtime`, `storage`, `search`, `mail`, `testing`,
     `frontend`, `broadcasting`, `broker` — or a new group name if it
     doesn't fit any of these; a new group also needs an entry in
     `InitCommand::groups()` for `ship init` to prompt for it)
   - `composeFragment(ShipEnvironment $environment, ?string $instanceName = null)`
     — the Docker Compose service(s) this contributes, keyed by service
     name. `$instanceName` is only non-null for an additional named
     instance (`ship.json`'s `additionalServices`); if a second instance
     of your service is a coherent idea, see
     [Supporting multiple named instances](#supporting-multiple-named-instances)
     below — if not (a runtime, a frontend toolchain, ...), ignore the
     parameter entirely, same as `NodeService`/`OctaneSwooleService`/etc.
     already do.
   - `environmentVariables(?string $instanceName = null)` — env vars
     merged into the `app` service. Same `$instanceName` meaning.
   - `removes()` — compose service names to drop (see `OctaneSwooleService`
     for why: Octane runtimes serve HTTP themselves, so they remove the
     `webserver` nginx service that a plain php-fpm setup needs)

2. **Register it** in `ServiceRegistry::defaults()`.

3. **Write a test.** `ComposeFileBuilderTest` shows the pattern: build a
   `ShipConfig` selecting your service, run it through `ComposeFileBuilder`,
   parse the resulting YAML, and assert on the parts you own.

## Things that will bite you

- **`ComposeFileBuilder` merges fragments targeting an existing service
  name** (like `app`) rather than overwriting — list-like keys
  (`environment`, `volumes`, `ports`, `networks`, `depends_on`) accumulate;
  everything else (like `command`) is overridden outright. If your service
  needs to add a port to `app` without erasing its build config, this is
  why that works. See `ComposeFileBuilder::mergeServiceFragment()`.

- **Named volumes are derived automatically**, not declared by hand. If
  your `composeFragment()` returns a volume mount like
  `"ship-myservice-data:/data"`, `ComposeFileBuilder` picks that up and adds
  it to the top-level `volumes:` key on its own — you don't need to (and
  shouldn't) add it yourself. Bind mounts (starting with `.` or `/`) are
  correctly left alone.

- **Pin image tags.** Every built-in service pins a specific, verified
  version rather than `:latest`: a floating tag makes builds
  non-reproducible, and an upstream change can break every project using it
  overnight. Check the registry for a current tag before adding a new
  service; `renovate.json` keeps the pinned tags current afterwards.

- **If your service's PHP client needs an extension** outside the
  Dockerfile's fixed set, implement `Ship\Contracts\RequiresPhpExtensions`
  rather than telling users to edit `phpExtensions` — `ComposeFileBuilder`
  merges the list into the app image's `PHP_EXTENSIONS` build arg.
  `MongoDbService` (`mongodb`), `MemcachedService` (`memcached`) and
  `SqlServerService` (`pdo_sqlsrv`) are the examples. Keep it for extensions that are heavy or rarely needed;
  a cheap, widely used one belongs in the Dockerfiles' fixed set instead
  (as `zip` and `sockets` are). The extensions are built
  into both images even when the service itself is development-only, as
  SQL Server's is.

- **If generated compose output starts depending on a change to a published
  stub** (the Dockerfiles, nginx or php-fpm config), bump
  `Ship\Support\StubRevision::REQUIRED` and `stubs/docker/php/.stub-revision`
  together. Projects whose published files predate it then get "run `ship
  init`" from `ship build`/`ship release` instead of a release whose images
  don't match its compose file.

- **If your service needs a config file** (like Garage's `garage.toml`),
  add it under `stubs/docker/<service>/` and publish it conditionally from
  `InitCommand::publishStubs()` — see the existing Garage branch there for
  the pattern.

- **A browser that has to reach the app** (a testing driver) shouldn't
  guess its address: it's `http://webserver` with nginx but
  `http://app:8000` with an Octane runtime, and either can be renamed.
  Implement `Ship\Contracts\NeedsAppUrl` instead, naming the container
  and variable that should hold it (`DuskService`: `APP_URL` on `app`;
  `CypressService`: `CYPRESS_BASE_URL` on its own container), and
  `ComposeFileBuilder` fills in the right URL.

- **If your service receives HTTP traffic of its own** (a WebSocket server
  the browser connects to, say), implement `Ship\Contracts\ExposesHttp`
  with its container port, role and, if it has one, a health path and the
  name of the setting holding its public address (`urlSetting`, e.g.
  `VITE_REVERB_HOST`). The
  service then joins `externalNetwork` alongside the site, and `ship
  release` lists it in `release.json`'s `http`. `ReverbService` and
  `CentrifugoService` are the examples.

- **If your service depends on `ship.json`** — a setting, or which other
  services are selected — implement `Ship\Contracts\ConfigAwareService`:
  `ComposeFileBuilder` hands it the config through `withConfig()` before
  asking for its fragment. `SiloService` reads its image variant that way,
  and `CentrifugoService` finds the project's Redis. Don't guess at another
  service's name: read it from the config, `serviceNames` included.

## Supporting multiple named instances

Most services only ever make sense once per project — one application
runtime, one frontend toolchain. Some don't: a project might genuinely
need a second database of a different engine, or a second Redis kept
separate from the default cache/session store. `ship.json`'s
`additionalServices` is how a project asks for that (see README's
"Multiple instances of a service"); on your side, supporting it means
using the `Ship\Services\SupportsNamedInstances` trait, already used by
`PostgresService`/`MySqlService`/`MariaDbService`/`MongoDbService`/
`SqlServerService`/`RedisService`/`ValkeyService`/`MemcachedService`/
`SeaweedFsService`/`GarageService`/`MeilisearchService`/
`ElasticsearchService`/`TypesenseService`/`MailpitService` — any of those is
a reference to copy from. The trait gives you two methods:

- `composeServiceName(?string $instanceName)` — `key()` itself for the
  default (`null`) instance, `"{key()}-{instanceName}"` otherwise. Use
  this as `composeFragment()`'s top-level array key and for deriving
  named volume names (`"ship-{$name}-data"`, not a hardcoded
  `"ship-redis-data"`), so two instances never collide.
- `envPrefix(?string $instanceName)` — empty for the default instance
  (so it uses the plain names a framework expects, `DB_HOST` and so on),
  or the uppercased instance name plus `_` otherwise (e.g. `"ANALYTICS_"`).
  Prefix every *connection-specific* variable your service returns with it.

One judgment call `environmentVariables()` has to make itself: not
every variable a service sets is connection-specific. `CACHE_STORE`
(Redis, Valkey, Memcached) and `SCOUT_DRIVER` (Meilisearch, Typesense) pick
the *app-wide default* store/driver — a named instance adds a second reachable service, it
doesn't change what the app uses by default, so those variables should
only be returned for the default (`null`) instance. `RedisService` and
`MeilisearchService` are the two examples of that split to copy from.

If running two of your service at once genuinely makes no sense (a
runtime, a frontend toolchain, a testing driver, a broadcasting
server, a message broker), don't use the trait — just accept and ignore
`$instanceName` in both methods, matching `NodeService`/
`OctaneSwooleService`/`OctaneRoadRunnerService`/`OctaneFrankenPhpService`/
`DuskService`/`PlaywrightService`/`CypressService`/`ReverbService`/
`CentrifugoService`/`RabbitMqService`. `InitCommand::GROUPS_SUPPORTING_ADDITIONAL_INSTANCES`
is the list of groups `ship init`'s "add another instance" prompt
offers at all (`database`, `cache`, `storage`, `search`, `mail`) — a
service in a group outside that list is never asked to support more
than one instance in the first place, hand-editing `ship.json` aside.

## Adding `ship db` support to a database service

A database `ServiceDefinition` can also implement
`Ship\Contracts\ProvidesDatabaseShell` to support `ship db` — a single
method,
`databaseShellCommand(?string $instanceName = null): array{service: string, command: list<string>}`,
returning the compose service to exec into and the command to run
there. Same `$instanceName` meaning as `composeFragment()` — use
`composeServiceName($instanceName)` from `SupportsNamedInstances` to
compute `service`, so `ship db` and `ship db <name>` both resolve to
the right container. `PostgresService` and `MySqlService` are the two
examples to copy from: both read the target container's *own*
environment variables (e.g. `$POSTGRES_USER`/`$POSTGRES_DB`) rather
than duplicating credentials, so the shell command always matches
whatever that container was actually provisioned with. `DbCommand`
fails with a clear message if the selected database service doesn't
implement this interface — it's optional, not part of the base
`ServiceDefinition` contract, since not every group (cache, storage,
...) has a client shell that makes sense here.

## Extending without forking

Third parties don't need to fork this package to add a service — a
separate Composer package can ship its own `ServiceDefinition` class and
have consumers list its fully-qualified class name in `ship.json`'s
`extensions` array. See `Ship\Extensions\ExtensionLoader` for exactly what
that mechanism does and doesn't do.

## Adding a framework adapter

A `Ship\Contracts\FrameworkAdapter` is the other extension point — one per
target framework rather than one per service. `LaravelAdapter` and
`SymfonyAdapter` and `TempestAdapter` are the built-in ones (listed once, in
`Ship\Frameworks\Frameworks::builtIn()`); any of them is a reference to copy
from for a new framework. Three methods:

- `detect(string $projectRoot): bool` — how this adapter recognizes the
  project as "mine" (Laravel's checks for `artisan` + `composer.json`).
  Only one adapter should realistically `detect()` true for a given
  project; if you're writing a second adapter for the same ecosystem,
  make `detect()` specific enough not to also match projects the built-in
  one already claims.
- `consoleCommands(): array<string, string>` — extra `ship <name>` proxy
  commands this framework gets, e.g. Laravel's `['artisan' => 'php
  artisan']`. `Application` wires each entry into a `ProxyCommand`
  automatically; you don't register these by hand.
- `releaseCommands(): list<string>` — shell commands run once at
  **production container boot** (not image build time — see the
  docblock on the interface method for why that distinction matters, it's
  not arbitrary). Return the framework's own optimize/cache-warming
  command(s) — Laravel's is `php artisan optimize`, Symfony's is `php
  bin/console cache:clear`. Return `[]` if your framework has nothing
  equivalent to run.

An adapter can also implement `Ship\Contracts\DescribesRelease` for `ship
release`: the framework's name, the health path the project defines (read
from the project, not assumed), the setting holding the site's public URL,
and the settings it can't run without, which then block a release when
`.env.production` lacks them. Laravel's adapter returns `laravel`, the
`health:` path from `bootstrap/app.php`, `APP_URL` and `APP_KEY`.

A framework whose projects commonly run long-lived processes implements
`Ship\Contracts\SuggestsProcesses`: `suggestedProcesses()` returns them by
name, with a label, the command, and optionally a health check command and an
`http` block, and `ship init` offers them as a checklist. Read them from the
project rather than assuming: Laravel's adapter offers Horizon only when
`laravel/horizon` is installed (with `horizon:status` as its check), Symfony's
consumes the transports `config/packages/messenger.yaml` defines, and Tempest's
offers its scheduler only when the code has `#[Schedule]` tasks.
`Ship\Support\ComposerPackages::has()` tells which packages are installed.

A framework that needs a newer PHP than ship's default implements
`Ship\Contracts\RequiresPhpVersion`: `ship init` then starts the PHP question
at that version and refuses an older one, and `ship config:test` reports it.
Tempest's adapter reads the PHP its installed `tempest/framework` requires from
`composer.lock` (8.5 for Tempest 3).

Like `ServiceDefinition`, a third-party adapter doesn't need to fork this
package — list its FQCN in `ship.json`'s `extensions` array the same way,
and `ExtensionLoader::load()` picks it up alongside any `ServiceDefinition`
extensions, in the same pass.
