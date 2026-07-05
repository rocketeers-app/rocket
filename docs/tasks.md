# Tasks

List server tasks in a team.

```bash
rocket tasks --team=<team>                           # List Tasks
rocket tasks --team=<team> --id=<id>                 # Get Task
rocket tasks --create --team=<team> -F name=<name> -F commands=<commands> # Create Task
rocket tasks --update --team=<team> --id=<id>        # Update Task
rocket tasks --delete --team=<team> --id=<id>        # confirms; --force to skip
rocket tasks --action=run --team=<team> --id=<id>    # Run
rocket tasks --action=schedule --team=<team> --id=<id> -F expression=<expression> # Create Schedule
rocket tasks --action=unschedule --team=<team> --id=<id> # confirms; --force to skip
rocket tasks --action=toggle-schedule --team=<team> --id=<id> # Schedule toggle
```

[← All commands](README.md)

