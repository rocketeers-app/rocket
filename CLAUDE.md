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
7. Push commits and tags to `rocketeers-app/ship` (`main` and `develop`). The split of `packages/rocket` to `rocketeers-app/rocket`, tag included, happens automatically — never run `git subtree split` or push to that repo yourself
8. `composer global update` — update the global install

## Packaging
- The PHAR bundles its own `vendor`, so `composer.json` `require` holds only `php`: every package, runtime ones too, goes in `require-dev`, and `composer global require rocketeers-app/rocket` installs nothing but the PHAR
- `box.json` has `exclude-dev-files: false` and a `finder` whose `notPath` leaves out the test-only packages (Pest, PHPUnit, Mockery, Pint and what only they need). A new test-only package goes in `notPath`, and its `autoload.files` go in `files` (the autoloader requires them at boot)
- `.gitattributes` `export-ignore`s everything but `builds/rocket` and `composer.json`, so the dist zip carries just those

## Architecture
- **Actions** (`app/Actions/`) — single-purpose classes using `lorisleiva/laravel-actions`
- **Commands** (`app/Commands/`) — hand-written commands; use `WithSteps` for steps (one Laravel task line each, never a progress bar; wrap no prompts in a step) and `OutputsJson` for `--json`
- **Generated commands** (`app/Console/`) — `OperationCommand` (one per API operation) and `ResourceCommand` (`rocket servers`), registered in `AppServiceProvider` from the schema. They live outside `app/Commands` because Laravel Zero auto-loads every class there.
- **API** (`app/Api/`) — Saloon: `RocketeersConnector`, requests, `ListPaginator` (both list shapes), `ApiErrorPresenter`. Every request goes through the `SendApiRequest` action.
- **Schema** (`app/Schema/`) — `SchemaCache` reads `~/.rocketeers/cache/api-v1.json`, else the bundled snapshot; `SchemaCompactor` reduces GET /v1/docs to what the CLI needs; `CommandNamer` turns route names into command names
- **Support** (`app/Support/`) — `Teams` (cached /me/teams with permissions), `PermissionGate`, `RecordPicker`, `IdentifierResolver`, `FieldPrompter`, `FieldValue`, `OutputRenderer`
- **StepException** / **ApiException** — thrown on failure; `OutputsJson` catches them and prints a clean error, or `{"error": {...}}` under `--json`
- **Kernel** (`app/Console/Kernel.php`, bound in `bootstrap/app.php`) — an unknown command is never handed to the default command (`home`) as an argument: with one likely command it asks to run that one, else it suggests; Symfony input errors (unknown option, missing value, too many arguments…) go through `InputErrorRenderer`: one plain sentence, the usage line and `--help`, or `{"error": {...}}` under `--json`

## Schema conventions
- Commands are named after the route: `api.team.<item>[.<sub>].<action>` → `item:sub:action`, with `index`→`list`, `show`→`read`, `store`→`create`, `destroy`→`delete`; a list route without a verb gains `list`
- Path parameters become arguments; a slug or name is resolved to the id the route binds on, a missing one becomes a search prompt
- Body fields become options; `{x}_id` becomes `--x` and is picked from the list the schema's `relation` hint names (convention fallback: `/{team}/{xs}`)
- A body field `{p}_id` is skipped when `{p}` is already in the path
- New API endpoints show up in the CLI after `api:refresh`; no CLI code is needed per endpoint

## Key conventions
- Every command has `--json`: stdout then carries exactly one JSON document and nothing prompts
- A missing argument, token or team is asked for when someone can answer (`canPrompt()`), and reported (as JSON under `--json`) when nobody can — never a Symfony `Not enough arguments`, so hand-written commands make their arguments optional. `EnsuresToken::ensureToken()` asks for a missing token (`ResolvesTeam` includes it); `ResolvesTeam::teamFilter()` resolves `--team` for commands that search every team, and a `--team` the token does not reach is picked again without being saved as the default. `FindEnvironmentAcrossTeams` searches the environments of every team when the slug is left out or matches nothing, and `IdentifierResolver` falls back to `RecordPicker` the same way
- Run local processes through `app(CommandLog::class)->run($process)` and SSH through `CreateSshConnection` (a `LoggedSsh`), so `-v` shows each command and its output; `hide()` secrets and `hideOutput()` on SSH reads of env files. Failure messages use `ProcessError::message()` (stderr, else stdout, never empty)
- Tests: `composer test` (Pest, Saloon `MockClient::global`, the bundled snapshot); `tests/.home` stands in for `~`
- `db:import {environment}` finds the environment by slug across every team, imports only MySQL/PostgreSQL that run on one of the team's servers (never ClickHouse, Tinybird, PlanetScale, RDS or other external databases), dumps each on the server it lives on, and asks which one when there are several (`--all` takes every one)
- `install {environment}` reads everything from `environments:read` and the first connected server (no `--server`/`--php`): local name is the slug minus `-{label}`, in `config('rocketeers.projects_path')` (`/var/www`); the main database is imported as that name and `DB_CONNECTION` points at it. With a `root_directory` (monorepo) the whole repository is cloned into `{projects_path}/{repository}` (`LocalRepositoryName`, from the clone URL), the app is installed in its root directory and named after its basename, the directory around it is parked in Herd, and switching the branch of an existing clone asks first (stops under `--json`)
- `deploy {environment}` starts a deployment (`api.team.environments.deploy`) and, like `deployments:follow`, polls `api.team.environments.deployments.steps` every `rocketeers.deployment_poll_interval` ms (`FollowsDeployments`): step titles, server and status only, never output. `DeploymentRenderer` prints finished steps as task lines and keeps running ones in a redrawn console section (lines stay two columns short of the terminal width, or the section miscounts its height). Ctrl+C stops following through `SignalableCommandInterface`
- `env:edit {environment}` reads the env with `api.team.environments.env.show` (`secrets:reveal`, returns `contents` + `checksum`) into a `PrivateScratchFiles` file (0700 dir, 0600 file, deleted in `finally`, on shutdown and on SIGINT), opens it with `OpenInEditor`, and saves with `api.team.environments.env.update` (`contents` + `if_match`; the API writes every server inline and answers per server plus `build_time_keys`). 422 reopens the editor with the edited text, 409 (env changed meanwhile) reopens it with the fresh env. The summary shows key names only (`SummarizeEnvChanges`). Then it asks to deploy (default yes only when `build_time_keys` is not empty) and follows through `FollowsDeployments::deployOperation()`/`startDeployment()`. Needs a terminal: refuses `--json` and no TTY
- `SchemaCache::findOrRefresh($route)` finds an operation, refreshing the schema at most once per run when the cached one predates it; use it for endpoints a hand-written command depends on
- `self-update` is our own command (`SelfUpdate`, not Laravel Zero's phar-updater): newest `vX.Y.Z` tag of `rocketeers-app/rocket` from the GitHub API, `builds/rocket` from raw.githubusercontent.com at that tag, checked with `--version`, then renamed over the running PHAR; after that nothing new may be loaded from the PHAR, so output is prepared up front and it exits
- SSH connections go through `CreateSshConnection` action (sets `LogLevel=ERROR`, disables strict host key checking)
- Use `herd isolate` for PHP version per site — NEVER use `herd use` (changes global PHP and breaks rocket)
- All actions should throw `StepException` with a descriptive message on failure
- Suppress MySQL password warnings with `2>/dev/null` on local mysql commands only — never on remote mysqldump (hides real errors)
