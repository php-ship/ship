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
  service building a `ship/Dockerfile` PHP stage, and switches whether
  `webserver` (nginx) exists based on runtime selection.
- One `ship/Dockerfile` builds five targets: `dev`, `builder`, `assets`,
  `prod` (the `app` service), and `dev-nginx`/`prod-nginx` (the
  `webserver` service). `Dockerfile.frankenphp` mirrors the same stage
  structure for the FrankenPHP runtime, which needs a different base
  image entirely.
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
- `ProxyCommand` reads raw `argv` instead of Symfony Console's parsed
  arguments, so flags meant for the proxied binary (`-m` in
  `artisan make:model Post -m`) aren't swallowed by Console's own option
  parser.
- CI matrix across Ubuntu/macOS/Windows x PHP 8.2/8.3/8.4.

## Known gaps

- **No automated test actually builds and runs the Docker images.**
  `ComposeFileBuilderTest` and friends assert on the generated YAML
  structure, which can't catch a docker-compose-schema-level rejection
  (Compose's schema requires `ports` to be a sequence, but an empty PHP
  array can dump as a YAML mapping) or a missing PHP extension a service
  implies but never installs. Worth a slow CI job that actually runs
  `docker compose up --build` against a generated stack, separate from
  the fast YAML-structure unit tests.
- **Reverb credentials aren't provisioned by `ship`.**
  `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET` are left for `php
  artisan install:broadcasting` (or a manual `.env` edit) to set, the
  same "still needed in the app" split every other `ServiceDefinition`
  uses (see README's Services table) — `ship` wires the infrastructure
  (container, port, host/server env vars), not app-level secrets.
  `ship init` warns if `laravel/reverb` itself isn't installed yet (the
  `reverb` container's command has nothing to run without it), but
  doesn't and can't provision the credentials themselves.
- **`ship up` can leave a late-building service at "Created" without
  starting it** when several services build images in the same run
  (`app`, `webserver`, `reverb` all build from `ship/Dockerfile`). The
  generated compose file's dependency graph is correct — this is a
  Docker Compose/Desktop concurrency quirk under simultaneous builds,
  not something `ship` generates wrong. Re-running `ship up`, or
  `docker compose -f ship/docker-compose.generated.yml up -d
  <service>` directly, starts it.
- **`InitCommand`'s interactive service-picker loop has no test
  coverage.** `InitCommandViteReminderTest` covers the Vite-reminder
  logic specifically (with an empty `ServiceRegistry` so every group is
  skipped), but the `select()`/`ChoiceQuestion` picker itself, and
  `publishStubs()`'s file-copying, remain untested.
- **Node version isn't actually configurable.** `NodeService` exists as
  a selectable group but its `composeFragment()`/`environmentVariables()`
  are both empty — Node is installed unconditionally in the Dockerfile's
  base stage at a fixed version. Making the version configurable needs
  the same build-arg plumbing `OCTANE_RUNTIME` uses.
- **`ProxyCommand`'s raw-argv forwarding** assumes the command name
  appears exactly once in `argv` and isn't itself a value of an earlier
  option — fine for `ship artisan ...` today, but would need revisiting
  if a global option is ever added before the command name.
- **Vite HMR still needs a few lines of `vite.config.js` added by hand.**
  `ship` publishes the dev server's port and `ship init` prints a
  reminder with the exact snippet when it detects `vite` in
  `package.json`, but can't safely auto-edit an *existing*
  `vite.config.js` itself — reliably patching arbitrary JS with a tool
  that has no JS parser isn't worth the fragility. See README's
  "Frontend dev server" section for the snippet.
- **Upgrading `ship` on an existing project needs `ship init` re-run.**
  `ship up` doesn't republish stub files (`ship/Dockerfile`,
  `ship/nginx/default.conf`, ...) — only `ship init` does. A project that
  upgrades `ship` and runs `ship up --prod` directly, without re-running
  `init`, keeps whatever stub files it already had on disk. No
  version-check/mismatch-warning mechanism exists yet.
- **Pinned third-party image tags will go stale over time**, same as any
  pinned dependency. No automated version-bump tooling
  (Renovate/Dependabot-style) is set up yet.

## Not started

- Mutagen-based sync mode as an opt-in alternative to bind mounts on
  Windows/macOS, for projects where bind-mount I/O is a bottleneck.
- A CI job that actually runs `docker compose up --build` against a
  generated stack and asserts on real HTTP/DB/cache behavior, closing
  the top "Known gaps" entry above.
- Cross-service coordination beyond what `ComposeFileBuilder::build()`
  already computes generically (`APP_URL`, `PHP_VERSION` backfill). A
  service that needs to know about *other* selected services beyond
  that has no clean mechanism yet — document the specific need if one
  comes up, rather than speculatively designing for it now.
