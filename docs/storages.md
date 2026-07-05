# Storages

List storages in a team or in an environment.

```bash
rocket storages --team=<team>                        # List Storages
rocket storages --team=<team> --environment=<environment> # List Storages
rocket storages --create --team=<team> --environment=<environment> -F name=<name> # Create Storage
rocket storages --action=attach --team=<team> --environment=<environment> -F storage_id=<storage_id> # Attach
rocket storages --action=sync --team=<team> --environment=<environment> # Sync
rocket storages --delete --team=<team> --environment=<environment> --id=<id> # confirms; --force to skip
```

[← All commands](README.md)

