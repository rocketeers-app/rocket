# Schedulers

List schedulers in a team, on a server, or in an environment.

```bash
rocket schedulers --team=<team>                      # List Schedulers
rocket schedulers --team=<team> --server=<server>    # List Crons
rocket schedulers --team=<team> --environment=<environment> # List Crons
rocket schedulers --create --team=<team> -F name=<name> -F command=<command> -F frequency=<frequency> # Create Scheduler
rocket schedulers --update --team=<team> --id=<id>   # Update Scheduler
rocket schedulers --delete --team=<team> --id=<id>   # confirms; --force to skip
```

[← All commands](README.md)

