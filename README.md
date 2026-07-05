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
GET endpoint is reachable.

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

Anything without a dedicated command (e.g. server stats) is reachable via
`rocket get`, for example:

```bash
rocket get servers/<id>/stats --team=team-rocket
rocket get /me            # absolute path — no team prefix
```
