# Rocket API commands

Generated from the live API docs at `https://rocketeers-app-v2.test/api/v1/docs`. Run `rocket docs` to refresh.

## Getting started

```bash
rocket auth                    # store your API token, set a default team
rocket url <base-url>          # point the CLI at your API
rocket use-team <team-slug>    # set a default team (or pass --team= each call)
rocket check                   # verify every documented endpoint is reachable
```

Every resource command is team-scoped: the team comes from `--team=<slug>` or your
configured default. Reads print a table (or `--json`); writes use `--create`,
`--update`, `--delete`, or `--action=<name>` with `-F key=value` body fields.

| Global flag | Description |
|-------------|-------------|
| `--team=` | Team slug (falls back to your default) |
| `--json` | Output raw JSON |
| `--id= / --server= / --environment= / --domain=` | Scope selectors |
| `-F, --field key=value` | Body field for writes (repeatable) |
| `--data '<json>'` | Raw JSON body for writes |
| `--force` | Skip the confirmation on destructive actions |

## Resources

- [apps](apps.md)
- [backups](backups.md)
- [certificates](certificates.md)
- [clients](clients.md)
- [commands](commands.md)
- [daemons](daemons.md)
- [databases](databases.md)
- [deployments](deployments.md)
- [domains](domains.md)
- [dns](dns.md)
- [environments](environments.md)
- [errors](errors.md)
- [finances](finances.md)
- [incidents](incidents.md)
- [issues](issues.md)
- [projects](projects.md)
- [redirects](redirects.md)
- [repositories](repositories.md)
- [schedulers](schedulers.md)
- [servers](servers.md)
- [services](services.md)
- [sites](sites.md)
- [storages](storages.md)
- [tasks](tasks.md)
- [vulnerabilities](vulnerabilities.md)

## Raw requests

Any endpoint is reachable directly, with the team prefixed automatically
(absolute `/…` paths are left as-is):

```bash
rocket get servers/<id>/stats --team=<team>
rocket post servers/<id>/reboot --team=<team>
rocket put sites/<id> --team=<team> -F name=Renamed
rocket delete sites/<id> --team=<team>   # confirms, or --force
```

