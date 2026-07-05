# Databases

List databases in a team, on a server, or in an environment.

```bash
rocket databases --team=<team>                       # List Databases
rocket databases --team=<team> --server=<server>     # List Databases
rocket databases --team=<team> --environment=<environment> # List Databases
rocket databases --create --team=<team>              # Create Database
rocket databases --delete --team=<team> --id=<id>    # confirms; --force to skip
rocket databases --action=attach --team=<team> --environment=<environment> --id=<id> # Attach
rocket databases --action=detach --team=<team> --environment=<environment> --id=<id> # confirms; --force to skip
```

[← All commands](README.md)

