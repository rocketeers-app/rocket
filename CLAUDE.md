# Rocket CLI

## Project
- Laravel Zero 11 PHAR application
- GitHub: `rocketeers-app/rocket`

## Release process
1. Commit changes
2. **Update version in `config/app.php`** — ALWAYS do this before building
3. `php rocket app:build` — builds PHAR to `builds/rocket`
4. Commit the built PHAR
5. Tag with `git tag vX.Y.Z`
6. Push commits and tags
7. `composer global update` — update the global install

## Architecture
- **Actions** (`app/Actions/`) — single-purpose classes using `lorisleiva/laravel-actions`
- **Commands** (`app/Commands/`) — use `WithSteps` trait for progress bar output
- **WithSteps** (`app/Commands/Concerns/WithSteps.php`) — progress bar with error handling
- **StepException** (`app/Exceptions/StepException.php`) — thrown by actions on failure, caught by `WithSteps` to show clean error output

## Key conventions
- SSH connections go through `CreateSshConnection` action (sets `LogLevel=ERROR`, disables strict host key checking)
- Use `herd isolate` for PHP version per site — NEVER use `herd use` (changes global PHP and breaks rocket)
- PHP requirement is `^8.2` in composer.json to support projects with older PHP versions
- All actions should throw `StepException` with a descriptive message on failure
- Suppress MySQL password warnings with `2>/dev/null` on local mysql commands only — never on remote mysqldump (hides real errors)
