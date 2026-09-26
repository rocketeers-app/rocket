# Rocket CLI

## Project
- Laravel Zero 12 PHAR application, PHP 8.4
- GitHub: `rocketeers-app/rocket`

## Release process
1. Commit changes
2. **Update version in `config/app.php`** — ALWAYS do this before building
3. `php rocket api:refresh --bundle` against production — refreshes `resources/api-v1.json`, the schema snapshot shipped in the PHAR
4. `php rocket app:build` — builds PHAR to `builds/rocket`
5. Commit the built PHAR
6. Tag with `git tag vX.Y.Z`
7. Push commits and tags
8. `composer global update` — update the global install

## Architecture
- **Actions** (`app/Actions/`) — single-purpose classes using `lorisleiva/laravel-actions`
- **Commands** (`app/Commands/`) — hand-written commands; use `WithSteps` for progress and `OutputsJson` for `--json`
- **Generated commands** (`app/Console/`) — `OperationCommand` (one per API operation) and `ResourceCommand` (`rocket servers`), registered in `AppServiceProvider` from the schema. They live outside `app/Commands` because Laravel Zero auto-loads every class there.
- **API** (`app/Api/`) — Saloon: `RocketeersConnector`, requests, `ListPaginator` (both list shapes), `ApiErrorPresenter`. Every request goes through the `SendApiRequest` action.
- **Schema** (`app/Schema/`) — `SchemaCache` reads `~/.rocketeers/cache/api-v1.json`, else the bundled snapshot; `SchemaCompactor` reduces GET /v1/docs to what the CLI needs; `CommandNamer` turns route names into command names
- **Support** (`app/Support/`) — `Teams` (cached /me/teams with permissions), `PermissionGate`, `RecordPicker`, `IdentifierResolver`, `FieldPrompter`, `FieldValue`, `OutputRenderer`
- **StepException** / **ApiException** — thrown on failure; `OutputsJson` catches them and prints a clean error, or `{"error": {...}}` under `--json`

## Schema conventions
- Commands are named after the route: `api.team.<item>[.<sub>].<action>` → `item:sub:action`, with `index`→`list`, `show`→`read`, `store`→`create`, `destroy`→`delete`; a list route without a verb gains `list`
- Path parameters become arguments; a slug or name is resolved to the id the route binds on, a missing one becomes a search prompt
- Body fields become options; `{x}_id` becomes `--x` and is picked from the list the schema's `relation` hint names (convention fallback: `/{team}/{xs}`)
- A body field `{p}_id` is skipped when `{p}` is already in the path
- New API endpoints show up in the CLI after `api:refresh`; no CLI code is needed per endpoint

## Key conventions
- Every command has `--json`: stdout then carries exactly one JSON document and nothing prompts
- Tests: `composer test` (Pest, Saloon `MockClient::global`, the bundled snapshot); `tests/.home` stands in for `~`
- `db:import {environment}` finds the environment by slug across every team, imports only MySQL/PostgreSQL that run on one of the team's servers (never ClickHouse, Tinybird, PlanetScale, RDS or other external databases), dumps each on the server it lives on, and asks which one when there are several (`--all` takes every one)
- SSH connections go through `CreateSshConnection` action (sets `LogLevel=ERROR`, disables strict host key checking)
- Use `herd isolate` for PHP version per site — NEVER use `herd use` (changes global PHP and breaks rocket)
- All actions should throw `StepException` with a descriptive message on failure
- Suppress MySQL password warnings with `2>/dev/null` on local mysql commands only — never on remote mysqldump (hides real errors)
