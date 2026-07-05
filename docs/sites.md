# Sites

List sites in a team or on a server.

```bash
rocket sites --team=<team>                           # List Sites
rocket sites --team=<team> --id=<id>                 # Get Site
rocket sites --team=<team> --server=<server>         # List Environments
rocket sites --create --team=<team> -F name=<name> -F manager_id=<manager_id> # Create Site
rocket sites --update --team=<team> --id=<id> -F name=<name> # Update Site
rocket sites --delete --team=<team> --id=<id>        # confirms; --force to skip
```

[← All commands](README.md)

