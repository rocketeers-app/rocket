# Issues

List repository issues in a team.

```bash
rocket issues --team=<team>                          # List Issues
rocket issues --team=<team> --id=<id>                # Get Issue
rocket issues --create --team=<team> -F repository_id=<repository_id> -F title=<title> # Create Issue
rocket issues --update --team=<team> --id=<id>       # Update Issue
rocket issues --action=labels --team=<team> --id=<id> -F labels=<labels> # Sync labels
rocket issues --action=toggle-status --team=<team> --id=<id> # Toggle status
```

[← All commands](README.md)

