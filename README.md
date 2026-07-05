# Rocket: Command Line Interface for Rocketeers

## Installation

```bash
composer global require rocketeers-app/rocket
```

## Commands

### Site management

| Command | Description |
|---------|-------------|
| `rocket install {site} [--server=] [--php=8.0]` | Install site |
| `rocket sync {site} [--server=]` | Sync site |
| `rocket tail {site} [--server=]` | Tail a log file on the remote server |
| `rocket db:import {site} [--server=] [--user=rocketeer]` | Import database |
| `rocket env:pull {site} [--server=]` | Pull `.env` for site from remote server |

### API commands

Interact with the Rocketeers API. Run `rocket auth` first — it stores your API
token (and an optional default team) in `~/.rocketeers/.env`. When a token is already
configured, `auth` verifies it and lets you change the key, set a default team, or
log out.

#### Account & configuration

| Command | Description |
|---------|-------------|
| `rocket auth` | Configure/verify your API token; change key, set default team, set API URL, or log out |
| `rocket url {url?}` | Set or show the API base URL (omit the URL to show the current one) |
| `rocket use-team {slug?}` | Set or show your default team (omit the slug to pick interactively) |
| `rocket me [--json]` | Show your Rocketeers profile |
| `rocket teams [--json]` | List your Rocketeers teams |
| `rocket check [--json]` | Verify the command registry against the live API docs |
| `rocket get {path} [--team=]` | Call any GET endpoint directly (raw JSON) |

#### Resources

Every resource command is team-scoped. The team comes from `--team=<slug>` or your
configured default (`use-team`); `--team` always wins.

```bash
rocket sites --team=team-rocket                 # list a team's sites
rocket sites --team=team-rocket --id=<id>       # show a single site
rocket daemons --team=team-rocket --server=<id> # daemons on a server
rocket daemons --team=team-rocket --env=<id>    # daemons in an environment
```

Common flags on every resource command:

| Flag | Description |
|------|-------------|
| `--team=` | Team slug (falls back to your default) |
| `--id=` | Fetch a single record |
| `--server=` | Scope to a server |
| `--environment=` / `--env=` | Scope to an environment |
| `--domain=` | Scope to a domain |
| `--page=` / `--per-page=` | Paginate (default: fetch all pages, max 50 per page) |
| `--json` | Output raw JSON |
| `--metadata` | Include result count, pagination meta, and response time in JSON |

Which scopes a command accepts depends on the resource — run it with an unsupported
combination and it lists the valid ones. `rocket check` confirms every documented
endpoint (read and write) is reachable.

| Command | Scopes (besides `--team`) |
|---------|---------------------------|
| `rocket apps` | `--id` |
| `rocket backups` | `--server` |
| `rocket certificates` | `--server` |
| `rocket clients` | `--id` |
| `rocket commands` | `--environment` |
| `rocket daemons` | `--server` · `--environment` |
| `rocket databases` | `--server` · `--environment` |
| `rocket deployments` | `--environment` (list) · `--environment --id` (show) |
| `rocket dns` | `--domain` |
| `rocket domains` | `--id` · `--server` |
| `rocket environments` | `--id` |
| `rocket errors` | `--id` · `--environment` |
| `rocket finances` | — |
| `rocket incidents` | `--id` · `--domain` |
| `rocket issues` | `--id` |
| `rocket projects` | `--id` |
| `rocket redirects` | `--environment` |
| `rocket repositories` | `--server` |
| `rocket schedulers` | `--server` · `--environment` |
| `rocket servers` | `--id` · `--environment` |
| `rocket services` | `--server` |
| `rocket sites` | `--id` · `--server` |
| `rocket storages` | `--environment` |
| `rocket tasks` | `--id` |
| `rocket vulnerabilities` | `--id` |

#### Writing (create / update / delete / actions)

Resource commands also write. Use `--create`, `--update`, `--delete`, or
`--action=<name>`, supplying body fields with repeatable `-F key=value` (and/or a
raw `--data '<json>'`). Missing required fields are prompted for interactively.

```bash
rocket sites --create --team=team-rocket -F name=Shop -F manager_id=<id>
rocket sites --update --id=<id> --team=team-rocket -F name=Renamed
rocket sites --delete --id=<id> --team=team-rocket          # confirms first
rocket servers --action=reboot --id=<id> --team=team-rocket # confirms first
rocket daemons --create --server=<id> --team=team-rocket \
  -F name=worker -F command="php artisan queue:work"        # nested scope
```

Write flags:

| Flag | Description |
|------|-------------|
| `--create` / `--update` / `--delete` | CRUD on the resource (`--update`/`--delete` need `--id`) |
| `--action=<name>` | Run a named action (see per-resource actions below) |
| `-F, --field key=value` | Body field (repeatable; `true`/`false`/`null`/integers are cast) |
| `--data '<json>'` | Raw JSON body (merged with `--field`) |
| `--force` | Skip the confirmation prompt on destructive actions |

**Destructive safety.** Deletes and impactful actions (`reboot`, `deploy`, `detach`,
DNS `clear`, …) prompt for confirmation first; pass `--force` to skip (scripts/CI).

Per-resource actions (beyond create/update/delete):

| Command | Actions (`--action=`) |
|---------|------------------------|
| `rocket backups` | `run` |
| `rocket databases` | `attach` · `detach` |
| `rocket deployments` | `--create` (triggers a deploy) |
| `rocket dns` | `--create`/`--update`/`--delete` records · `clear` · `template` |
| `rocket domains` | `--create` · `sync` · `response-time` |
| `rocket environments` | `auto-deploy` · `sla-status` · `attach-server` · `detach-server` · `set-web` |
| `rocket errors` | `ignore` · `--delete` (occurrences) |
| `rocket incidents` | `acknowledge` · `investigate` · `monitor` · `resolve` |
| `rocket issues` | `labels` · `toggle-status` |
| `rocket servers` | `reboot` · `sync` · `rename` · `access` · `blacklist` · `check-availability` · `check-username` · `install-service` |
| `rocket storages` | `attach` · `sync` |
| `rocket tasks` | `run` · `schedule` · `unschedule` · `toggle-schedule` |

#### Raw requests

Any endpoint is reachable with the raw verb commands (the `gh api` model):

```bash
rocket get servers/<id>/stats --team=team-rocket   # absolute /me works too
rocket post servers/<id>/reboot --team=team-rocket
rocket put sites/<id> --team=team-rocket -F name=Renamed
rocket delete sites/<id> --team=team-rocket        # confirms, or --force
```
