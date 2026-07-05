# Servers

List servers in a team or in an environment.

```bash
rocket servers --team=<team>                         # List Servers
rocket servers --team=<team> --id=<id>               # Get Server
rocket servers --team=<team> --environment=<environment> # List Servers
rocket servers --create --team=<team> -F name=<name> -F provider_account_id=<provider_account_id> # Create Server
rocket servers --action=sync --team=<team>           # Sync
rocket servers --action=reboot --team=<team> --id=<id> # confirms; --force to skip
rocket servers --action=rename --team=<team> --id=<id> -F name=<name> # Update Name
rocket servers --action=access --team=<team> --id=<id> -F allowed_ips=<allowed_ips> # Update Access
rocket servers --action=blacklist --team=<team> --id=<id> -F blacklisted_ips=<blacklisted_ips> # Update Access blacklist
rocket servers --action=check-availability --team=<team> -F provider_account_id=<provider_account_id> # Check availability
rocket servers --action=check-username --team=<team> --id=<id> -F username=<username> # Check username
rocket servers --action=install-service --team=<team> --id=<id> -F service=<service> # Create Service
```

[← All commands](README.md)

