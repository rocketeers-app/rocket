# Environments

List environments in a team.

```bash
rocket environments --team=<team>                    # List Environments
rocket environments --team=<team> --id=<id>          # Get Environment
rocket environments --create --team=<team> -F name=<name> # Create Environment
rocket environments --update --team=<team> --id=<id> # Update Environment
rocket environments --delete --team=<team> --id=<id> # confirms; --force to skip
rocket environments --action=auto-deploy --team=<team> --id=<id> -F auto_deploy=<auto_deploy> # Update Auto deploy
rocket environments --action=sla-status --team=<team> --id=<id> -F sla_status=<sla_status> # Update SLA status
rocket environments --action=attach-server --team=<team> --id=<id> --server=<server> # Attach
rocket environments --action=detach-server --team=<team> --id=<id> --server=<server> # confirms; --force to skip
rocket environments --action=set-web --team=<team> --id=<id> --server=<server> # Set web
```

[← All commands](README.md)

