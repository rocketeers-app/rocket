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

Interact with the Rocketeers API. Requires an API token (see `rocket api:auth`).

| Command | Description |
|---------|-------------|
| `rocket api:auth` | Configure your Rocketeers API token |
| `rocket api:me [--json]` | Show your Rocketeers profile |
| `rocket api:teams [--json]` | List your Rocketeers teams |

The following commands require a `{team}` slug argument and support `--json` for raw JSON output and `--metadata` to include result counts, pagination meta, and response time:

| Command | Description |
|---------|-------------|
| `rocket api:clients {team}` | List clients |
| `rocket api:daemons {team}` | List daemons |
| `rocket api:databases {team}` | List databases |
| `rocket api:domains {team}` | List domains |
| `rocket api:errors {team}` | List errors |
| `rocket api:finances {team}` | Show finance overview |
| `rocket api:incidents {team}` | List incidents |
| `rocket api:projects {team}` | List projects |
| `rocket api:repositories {team}` | List repositories |
| `rocket api:schedulers {team}` | List schedulers |
| `rocket api:servers {team}` | List servers |
| `rocket api:sites {team}` | List sites |
| `rocket api:storages {team}` | List storages |
| `rocket api:tasks {team}` | List server tasks |
