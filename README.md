# Rocket

**The command line interface for [Rocketeers](https://rocketeers.app).**

Rocket gets a site that runs on a Rocketeers server working on your Mac in one command. It clones the repository, pulls the environment file, copies the production database and sets up HTTPS with Laravel Herd or Valet. It works with Laravel apps, plain WordPress, and Bedrock/Radicle.

```bash
rocket install acme-production
```

```
 14/14 [============================] Done!

View in browser: https://acme.test
```

## Requirements

- macOS with [Laravel Herd](https://herd.laravel.com) or [Laravel Valet](https://laravel.com/docs/valet)
- PHP 8.2+ and Composer
- A local MySQL server with a passwordless `root` user on `127.0.0.1`
- `git`, `rsync` and `ssh`
- [nvm](https://github.com/nvm-sh/nvm) for `rocket install` on sites with frontend assets
- SSH access as the `rocketeer` user on your Rocketeers servers
- A writable `/var/www` directory that Herd or Valet serves (for example `herd park` inside `/var/www`)

## Installation

```bash
composer global require rocketeers-app/rocket
```

Make sure Composer's global `bin` directory is on your `$PATH`, then check that it works:

```bash
rocket
```

To update:

```bash
rocket self-update
```

This takes the newest version tagged on GitHub, checks that the download runs as that version, and only then replaces Rocket. `composer global update rocketeers-app/rocket` works too, but Packagist can lag a few minutes behind a release.

## Getting started

### 1. Set up your token

In Rocketeers, go to **Settings, API** and click **Create Rocket CLI token**. Copy the command it shows and run it:

```bash
rocket setup-token {token}
```

Leave the token out and Rocket asks for it; any other command asks for it too when none is saved yet. It checks the token right away against the `/me` endpoint and only saves a token that works, to `~/.rocketeers/.env`, readable by you alone. Check which account you're using at any time with `rocket me`.

### 2. Sync your SSH config

```bash
rocket ssh:config
```

This needs a token from step 1. It downloads the SSH host entries for all your sites and servers and writes them to `~/.ssh/config` between two `### ROCKETEERS APP ###` markers.

> [!WARNING]
> If `~/.ssh/config` doesn't have the Rocketeers markers yet, the file is **overwritten**. Back up any existing entries first and add them back outside the markers afterwards.

Once this is done, every site has an SSH alias, so you can refer to a site by name in all the other commands.

### 3. Install a site

```bash
rocket install acme-production
```

## Concepts

Most commands take a `site` argument and an optional `--server` option.

| Name | Meaning |
| --- | --- |
| `site` | The site's directory name on the server, so `/var/www/{site}` |
| `--server` | The SSH host to connect to. **Defaults to the site name**, which works with the aliases from `rocket ssh:config` |
| Local name | Taken from the repository name of the remote git origin. If there's no origin, Rocket removes a trailing suffix from the site name (`acme-production` becomes `acme`) |

Sites are installed locally in `/var/www/{name}` and served at `https://{name}.test`. The local database is also called `{name}`.

Rocket detects the type of project on the server by itself:

- **WordPress**: there's a `wp-config.php` in the site root or in `public/`
- **Bedrock / Radicle**: there's a `config/application.php`. These use a `.env` file, like Laravel
- **Laravel**: anything else

## Commands

Add `-v` (or `--verbose`) to `rocket install`, `rocket db:import` or `rocket env:pull` to see what happens: every step on its own line, every command Rocket runs locally or over SSH, and what it prints. Database passwords are masked and the contents of env files are never shown. With `--json`, this goes to stderr so stdout stays one JSON document.

### `rocket install`

Sets up a complete local copy of an environment, found by its slug in any of your teams.

```bash
rocket install [environment] [--database=] [--all] [--team=]
```

Everything comes from the API and from the environment's first connected server, so there's no `--server` or `--php`. Rocket:

1. Reads the environment: PHP version, branch, label, directory and repository. Without a repository in Rocketeers, it reads the git origin of the current release over SSH
2. Picks the local name: the slug without its label (`routine-production` becomes `routine`), installed in `/var/www/{name}`
3. Clones the repository on the environment's branch. If it's already cloned, it fetches, checks out the environment's branch (the repository's default branch when the environment has none), even when the clone is on another one, and fast-forwards it to origin. It stops when the branch isn't on origin or can't be fast-forwarded. With local changes, it asks to stash them first, and stops if you say no
4. Pulls the env file (or `wp-config.php`) as the environment's own user and changes it for local use: the database points at `127.0.0.1` with your local user, and a server `DB_SOCKET` is cleared
5. Imports the MySQL and PostgreSQL databases on your own servers. With several, it asks whether to import one or all of them, and with all, which one is the main connection. The main database is imported as `{name}` and `DB_CONNECTION` points at it; the others keep their remote name
6. Isolates the PHP version with `herd isolate` (never `herd use`)
7. Runs `composer install`, `php artisan migrate --force` and `npm install`, then `npm run build` (or `prod`, or `production`, whichever script comes first; skipped when there is none). npm runs after `nvm use` when there's an `.nvmrc` (in the project or above it), with the nvm in `$NVM_DIR`, Herd's, `~/.nvm` or Homebrew's, and installs that Node version when it's missing. Each step only runs when the project has it
8. Secures the site with HTTPS, at `https://{name}.test`

Without a terminal (or with `--json`), Rocket imports the database named in the remote `DB_DATABASE`. Pass `--database=<name>` to pick one, or `--all` to import them all (with `--database=` naming the main one).

#### Monorepos

When the environment has a root directory (like `apps/api`), Rocket clones the whole repository and installs the app in that directory:

- The repository goes into `/var/www/{repository}`, named after its clone URL (`git@github.com:acme/monorepo.git` becomes `/var/www/monorepo`). The app lives in `/var/www/monorepo/apps/api`
- The local name is the last part of the root directory (`api`), so the main database is imported as `api` and the env file is written to `apps/api/.env`
- Rocket runs `herd park` in the directory around the app (`/var/www/monorepo/apps`), so the site is `https://api.test`, next to any other app in that directory
- When the repository is already cloned on another branch, Rocket asks before switching it, because that affects every app in the repository. Without a terminal (or with `--json`), it stops instead
- `npm install` runs at the repository root when its `package.json` has `workspaces`, else in the app. An `.nvmrc` at the repository root counts too

### `rocket deploy`

Deploys an environment, found by its slug in any of your teams, and follows it live until it's done.

```bash
rocket deploy [environment] [--detach] [--team=]
rocket deployments:follow [environment] [deployment] [--team=]
```

Every finished step gets its own line, with the server in front of it when the environment has more than one. In a terminal, the steps running right now stay below them as `RUNNING` lines until they finish:

```
  Deploying acme-production (main · 5f5cdc1) to web-1, web-2

  [web-1] Cloning the repository ..................................... 2s DONE
  [web-2] Cloning the repository ..................................... 3s DONE
  [web-2] Installing composer dependencies ........................ 2s RUNNING
  [web-1] Running migrations ..................................... 0ms RUNNING
```

- A deployment queued behind a running one shows that it's waiting
- When it fails, the step that broke shows `FAIL` and Rocket prints why; the exit code is 1 (also when it's cancelled)
- `Ctrl+C` stops following; the deployment keeps running. `rocket deployments:follow {environment}` picks up the latest deployment again (or the one you name)
- `--detach` only starts the deployment. With `--json`, stdout carries one JSON document once the deployment is done: the deployment and its steps
- Without a terminal (CI), only finished steps are printed

### `rocket sync`

Updates a local site with the files, config and database from the server.

```bash
rocket sync [site] [--server=]
```

1. Rsyncs `/var/www/{site}/current/` to `/var/www/{name}/`, leaving out `.env`, `node_modules`, `vendor` and `storage`
2. Pulls `wp-config.php` (WordPress) or `.env` (Laravel, Bedrock, Radicle) and changes it for local use
3. Replaces the local database with a fresh copy of the remote one
4. Secures the site with HTTPS

> [!CAUTION]
> Rsync runs with `--delete`, and the local database is dropped before the import. Any local changes to the synced files or the database will be lost.

### `rocket db:import`

Replaces the local database with the remote one, without changing any files.

```bash
rocket db:import [environment] [--database=] [--all] [--as=] [--team=]
```

Rocket reads the database credentials from the remote `.env` or `wp-config.php`. It then drops and recreates the local database, and streams a gzipped `mysqldump` over SSH straight into your local MySQL. Foreign key checks are turned off during the import, and the time zone of the local MySQL server is set to UTC.

### `rocket env:pull`

Pulls only the environment configuration.

```bash
rocket env:pull [environment] [--team=]
```

This writes the remote `.env` (or `wp-config.php` for WordPress) to your local site and changes it for local use.

### `rocket env:edit`

Opens the env file of an environment in your editor. Close the editor and Rocket saves it to every server of the environment, then asks whether to deploy.

```bash
rocket env:edit [environment] [--deploy] [--no-deploy] [--team=]
```

```
  Changes to acme-production:
  + MAIL_FROM_ADDRESS
  ~ VITE_APP_NAME

 ┌ Save the env of acme-production and write it to its servers? ┐
 │ Yes                                                          │
 └──────────────────────────────────────────────────────────────┘

  Saving on web-1 ................................................... DONE
  Saving on web-2 ................................................... DONE

  VITE_APP_NAME is read at build time, so it takes effect after a deploy.
```

- The editor is `$VISUAL`, else `$EDITOR`, else `vi`. A GUI editor needs its wait flag, like `EDITOR="code --wait"`
- The file lives in a private temporary directory while you edit and is deleted afterwards, also when you stop with `Ctrl+C`. The summary names the keys you added, changed or removed, never their values
- Saving writes the file to every server and reloads the app, so most changes are live without a deploy. When a key read at build time changed (like `VITE_*`), the deploy question defaults to yes; otherwise to no. Yes starts a deployment and follows it like `rocket deploy`. `--deploy` and `--no-deploy` answer it up front
- When Rocketeers refuses the file (invalid syntax, a Laravel app without `APP_KEY`), Rocket shows why and reopens the editor with your changes. When someone changed the env while you were editing, nothing is saved: Rocket names the keys you had changed and reopens the editor with the current env
- Needs the `secrets:reveal` permission to read the file and `secrets:update` to save it (plus `deployments:create` to deploy). Every read is logged in Rocketeers
- It needs a terminal, so it refuses `--json`. WordPress has no env file (its configuration is in `wp-config.php`)

### `rocket tail`

Follows a log file on the server in real time.

```bash
rocket tail [site] [--server=] [--file=]
```

Rocket finds every `*.log` file in `/var/www/{site}/persistent/storage/logs` and `/var/www/{site}/logs`, lets you pick one (or takes `--file=`, by name or path), and runs `tail -f` on it. Press `Ctrl+C` to stop.

### `rocket setup-token`

Authenticates Rocket with a Rocket CLI token: it checks the token against `/me` and saves it when it works. Without the token it asks for it. See [Getting started](#1-set-up-your-token).

```bash
rocket setup-token [token]
```

### `rocket me`

Shows the name and email of the Rocketeers account your saved API token belongs to.

### `rocket ssh:config`

Updates your local SSH config with all sites and servers from your Rocketeers account. See [Getting started](#2-sync-your-ssh-config).

## Local configuration changes

When Rocket pulls an environment file, it changes these values so the site works on your machine:

**Laravel / Bedrock / Radicle (`.env`)**

| Key | Local value |
| --- | --- |
| `APP_ENV` | `local` |
| `APP_DEBUG` | `true` |
| `APP_URL` | `https://{name}.test` |
| `CACHE_DRIVER` | `array` |
| `DB_HOST` | `127.0.0.1` |
| `DB_DATABASE` | `{name}` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | _(empty)_ |
| `SESSION_DOMAIN` | removed |

**WordPress (`wp-config.php`)**

| Constant | Local value |
| --- | --- |
| `DB_NAME` | `{name}` |
| `DB_USER` | `root` |
| `DB_PASSWORD` | _(empty)_ |
| `DB_HOST` | `127.0.0.1` |
| `WP_DEBUG` | `true` |

All other values stay the same as on the server.

> [!IMPORTANT]
> The rest of your production config is copied as-is. That includes API keys, mail settings and queue connections, so check them before you send mail or run jobs locally.

## Configuration

Rocket keeps its token in `~/.rocketeers/.env`, written by `rocket setup-token`. To use a different token, create a new Rocket CLI token and run `rocket setup-token` again. Rocket talks to `https://api.rocketeersapp.com/v1` by default; set `API_URL` in the same file to point it somewhere else.

## Troubleshooting

- **`No Herd or Valet found`**: install Herd or Valet and make sure `herd` or `valet` is on your `$PATH`.
- **`Could not create local database`**: check that MySQL is running and that `mysql -u root` works without a password.
- **`Could not fetch .env from remote server`**: check that `ssh rocketeer@{server}` works and that the site name matches the directory in `/var/www` on the server.
- **SSH asks for a password or the host can't be found**: run `rocket ssh:config` again, or pass `--server=` with a host you can reach.

## Development

```bash
git clone git@github.com:rocketeers-app/rocket.git
cd rocket
composer install
php rocket list
```

Rocket is built with [Laravel Zero](https://laravel-zero.com):

- `app/Commands`: the CLI commands. They use the `WithSteps` trait to show each step as a line (`Importing routine ........ 2s DONE`)
- `app/Actions`: small classes that each do one thing, built on [`lorisleiva/laravel-actions`](https://laravelactions.com). When something goes wrong they throw a `StepException`, which `WithSteps` shows as a clean error message
- Every SSH connection goes through the `CreateSshConnection` action

### Building a release

1. Set the new version in `config/app.php`
2. Build the PHAR with `php rocket app:build`, which writes it to `builds/rocket`
3. Commit the build, tag it (`git tag vX.Y.Z`) and push the commits and tags

## License

Rocket is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).
