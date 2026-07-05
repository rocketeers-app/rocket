# Daemons

List daemons in a team, on a server, or in an environment.

```bash
rocket daemons --team=<team>                         # List Daemons
rocket daemons --team=<team> --server=<server>       # List Daemons
rocket daemons --team=<team> --environment=<environment> # List Daemons
rocket daemons --create --team=<team> -F name=<name> -F command=<command> # Create Daemon
rocket daemons --update --team=<team> --id=<id>      # Update Daemon
rocket daemons --delete --team=<team> --id=<id>      # confirms; --force to skip
```

[← All commands](README.md)

