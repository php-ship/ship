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
     `frontend` — or a new group name if it doesn't fit any of these)
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
  version rather than `:latest` — floating tags make builds
  non-reproducible and, per the MinIO situation this project exists partly
  in reaction to, an upstream project going sideways can silently break
  everyone using `:latest` overnight. Check the registry for a current tag
  before adding a new service.

- **If your service needs a config file** (like Garage's `garage.toml`),
  add it under `stubs/docker/<service>/` and publish it conditionally from
  `InitCommand::publishStubs()` — see the existing Garage branch there for
  the pattern.

- **Cross-service coordination is a known architectural gap**, not
  something to work around per-service. If your service needs to know
  about *other* selected services (the way Dusk's correct `APP_URL` depends
  on whether an Octane runtime was also picked), don't hardcode a guess —
  document the limitation the way `DuskService` currently does, and see
  `docs/roadmap.md` for the direction a real fix should take.

## Supporting multiple named instances

Most services only ever make sense once per project — one application
runtime, one frontend toolchain. Some don't: a project might genuinely
need a second database of a different engine, or a second Redis kept
separate from the default cache/session store. `ship.json`'s
`additionalServices` is how a project asks for that (see README's
"Multiple instances of a service"); on your side, supporting it means
using the `Ship\Services\SupportsNamedInstances` trait, already used by
`PostgresService`/`MySqlService`/`RedisService`/`SeaweedFsService`/
`GarageService`/`MeilisearchService`/`MailpitService` — any of those is
a reference to copy from. The trait gives you two methods:

- `composeServiceName(?string $instanceName)` — `key()` itself for the
  default (`null`) instance, `"{key()}-{instanceName}"` otherwise. Use
  this as `composeFragment()`'s top-level array key and for deriving
  named volume names (`"ship-{$name}-data"`, not a hardcoded
  `"ship-redis-data"`), so two instances never collide.
- `envPrefix(?string $instanceName)` — empty for the default instance
  (so its variable names stay exactly what they've always been, zero
  migration for existing single-instance projects), or the uppercased
  instance name plus `_` otherwise (e.g. `"ANALYTICS_"`). Prefix every
  *connection-specific* variable your service returns with it.

One judgment call `environmentVariables()` has to make itself: not
every variable a service sets is connection-specific. `CACHE_STORE`
(Redis) and `SCOUT_DRIVER` (Meilisearch) pick the *app-wide default*
store/driver — a named instance adds a second reachable service, it
doesn't change what the app uses by default, so those variables should
only be returned for the default (`null`) instance. `RedisService` and
`MeilisearchService` are the two examples of that split to copy from.

If running two of your service at once genuinely makes no sense (a
runtime, a frontend toolchain, a testing driver, a broadcasting
server), don't use the trait — just accept and ignore `$instanceName`
in both methods, matching `NodeService`/`OctaneSwooleService`/
`OctaneRoadRunnerService`/`OctaneFrankenPhpService`/`DuskService`/
`ReverbService`. `InitCommand::GROUPS_SUPPORTING_ADDITIONAL_INSTANCES`
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
`SymfonyAdapter` are the two built-in ones; either is a reference to copy
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

Like `ServiceDefinition`, a third-party adapter doesn't need to fork this
package — list its FQCN in `ship.json`'s `extensions` array the same way,
and `ExtensionLoader::loadFrameworkAdapters()` picks it up.
