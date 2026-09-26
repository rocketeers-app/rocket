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
composer global update rocketeers-app/rocket
```

## Getting started

### 1. Sync your SSH config

```bash
rocket ssh:config
```

The first time you run this, Rocket asks for your Rocketeers API token and saves it to `~/.rocketeers/.env`. It then downloads the SSH host entries for all your sites and servers and writes them to `~/.ssh/config` between two `### ROCKETEERS APP ###` markers.

> [!WARNING]
> If `~/.ssh/config` doesn't have the Rocketeers markers yet, the file is **overwritten**. Back up any existing entries first and add them back outside the markers afterwards.

Once this is done, every site has an SSH alias, so you can refer to a site by name in all the other commands.

### 2. Install a site

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

### `rocket install`

Sets up a complete local copy of a site from scratch.

```bash
rocket install {site} [--server=] [--php=8.0]
```

For Laravel projects, it:

1. Reads the repository URL, repository name and current branch from the server
2. Clones the repository to `/var/www/{name}` and checks out that branch (skipped if it's already cloned)
3. Isolates the PHP version with `herd isolate` or `valet isolate` (`--php`, default `8.0`)
4. Pulls the remote `.env` and changes it for local use
5. Creates a new local database and imports the remote database into it
6. Runs `composer install`, `php artisan migrate --force`, `nvm install`, `npm install` and `npm run dev`
7. Secures the site with HTTPS

WordPress sites don't have a git-based install. For those, `rocket install` runs [`rocket sync`](#rocket-sync) instead.

### `rocket sync`

Updates a local site with the files, config and database from the server.

```bash
rocket sync {site} [--server=]
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
rocket db:import {site} [--server=]
```

Rocket reads the database credentials from the remote `.env` or `wp-config.php`. It then drops and recreates the local database, and streams a gzipped `mysqldump` over SSH straight into your local MySQL. Foreign key checks are turned off during the import, and the time zone of the local MySQL server is set to UTC.

### `rocket env:pull`

Pulls only the environment configuration.

```bash
rocket env:pull {site} [--server=]
```

This writes the remote `.env` (or `wp-config.php` for WordPress) to your local site and changes it for local use.

### `rocket tail`

Follows a log file on the server in real time.

```bash
rocket tail {site} [--server=]
```

Rocket finds every `*.log` file in `/var/www/{site}/persistent/storage/logs` and `/var/www/{site}/logs`, lets you pick one, and runs `tail -f` on it. Press `Ctrl+C` to stop.

### `rocket ssh:config`

Updates your local SSH config with all sites and servers from your Rocketeers account. See [Getting started](#1-sync-your-ssh-config).

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

Rocket keeps its own settings in `~/.rocketeers/.env`:

```dotenv
API_TOKEN=your-rocketeers-api-token
```

To use a different token, edit this file or delete the line. Rocket will ask for a new token the next time you run `rocket ssh:config`.

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

- `app/Commands`: the CLI commands. They use the `WithSteps` trait to show a progress bar
- `app/Actions`: small classes that each do one thing, built on [`lorisleiva/laravel-actions`](https://laravelactions.com). When something goes wrong they throw a `StepException`, which `WithSteps` shows as a clean error message
- Every SSH connection goes through the `CreateSshConnection` action

### Building a release

1. Set the new version in `config/app.php`
2. Build the PHAR with `php rocket app:build`, which writes it to `builds/rocket`
3. Commit the build, tag it (`git tag vX.Y.Z`) and push the commits and tags

## License

Rocket is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).
