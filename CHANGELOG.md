# Changelog

## Unreleased

### Breaking

- **`release.json` has a new shape: the ship-release contract.** `ship release`
  now writes `"format": "ship-release"` and the fields described in
  [docs/release-contract.md](docs/release-contract.md) (JSON Schema:
  `schema/ship-release.schema.json`). The old top-level `gitCommit` and
  `shipVersion` are now `source.gitCommit` and `builder.version`, and each
  `images[]` entry has `ref` (was `tag`), `id`, `file`, `sha256` and
  `services`. The contract is a draft until it has been tested end to end with
  a deployment tool; after that it only grows.
- **`ship release` needs `name` in `ship.json`.** It fails with the name to add
  (the one the project resolves to today, which keeps an existing deployment's
  volumes).
- **`ship release` no longer overwrites an existing `dist/ship/<tag>`.** Pass
  `--force` to replace it.
- **Laravel's `APP_KEY` is required in `.env.production`** for `ship release`,
  like the selected services' credentials.

### Added

- `ship release --force` and `--no-env`.
- `ship release` pins every image it doesn't build (`name:tag@sha256:…`),
  records the images' platform, IDs and archive checksums, writes a README with
  the deploy steps, and writes the release atomically.
- MariaDB, MongoDB, SQL Server (Developer Edition, development only),
  Elasticsearch, RabbitMQ, Playwright and Cypress services.
- Centrifugo, a framework-agnostic alternative to Reverb in the
  `broadcasting` group, with an opt-in Redis engine (`CENTRIFUGO_ENGINE=redis`)
  for running several Centrifugo servers as one.
- `zip` and `sockets` are in every PHP image.

### Fixed

- `externalNetwork` is attached to every service that receives HTTP
  (`webserver`, or `app` under Octane, and Reverb), not only `app`, so a
  reverse proxy on that network can reach the site.
- Dusk's `APP_URL` with an Octane runtime is `http://app:8000`, where Octane
  listens.
