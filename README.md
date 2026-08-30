# Rocket: Command Line Interface for Rocketeers

## Installation

```bash
composer global require rocketeers-app/rocket
```

## Optional: Add API token for Rocketeers app

When running `rocket ssh:config` you will be asked to provide a Rocketeers API token.

## Updating

```bash
composer global update
```

Built-in binaries also support `rocket self-update`, which checks for and
installs the latest release in place without going through Composer. If you
installed Rocket via `composer global require`, prefer `composer global
update` instead, so Composer's own record of the installed version stays in
sync.
