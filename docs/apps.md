# Apps

List apps in a team.

```bash
rocket apps --team=<team>                            # List Apps
rocket apps --team=<team> --id=<id>                  # Get App
rocket apps --create --team=<team> -F name=<name> -F manager_id=<manager_id> # Create App
rocket apps --update --team=<team> --id=<id> -F name=<name> # Update App
rocket apps --delete --team=<team> --id=<id>         # confirms; --force to skip
```

[← All commands](README.md)

