<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\RemoteSiteInfo;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class ImportRemoteDatabase
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $credentials = $this->fetchCredentials($site, $server);
        $this->prepareLocalDatabase($credentials['name']);
        $this->importDatabase($credentials, $server);
    }

    /**
     * Pass an already-fetched $remoteSite (e.g. from the Install/Sync
     * commands, which need it anyway) to skip re-detecting whether the site
     * is WordPress/Bedrock and what its repository name is over SSH again.
     */
    public function fetchCredentials($site, $server, ?RemoteSiteInfo $remoteSite = null): array
    {
        $remoteSite ??= (new DetectRemoteSite)($site, $server);

        $credentials = ['name' => $remoteSite->repositoryName];

        if ($remoteSite->isWordPress && ! $remoteSite->isBedrock) {
            $credentials = array_merge($credentials, $this->fetchWordPressCredentials($site, $server));
        } else {
            $credentials = array_merge($credentials, $this->fetchEnvCredentials($site, $server));
        }

        return $credentials;
    }

    protected function fetchEnvCredentials($site, $server): array
    {
        $envVars = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
        $credentials = [];

        foreach ($envVars as $var) {
            $process = (new CreateSshConnection)($server)
                ->execute([
                    'sudo grep "^'.$var.'=" /var/www/'.$site."/current/.env | grep -v -e '^\s*#' | cut -d '=' -f 2-",
                ]);

            $value = trim($process->getOutput());

            if (empty($value) && $var === 'DB_HOST') {
                $value = '127.0.0.1';
            } elseif (empty($value) && $var !== 'DB_PASSWORD') {
                throw new StepException("Could not fetch {$var} from remote .env.");
            }

            $credentials[$var] = $value;
        }

        return $credentials;
    }

    protected function fetchWordPressCredentials($site, $server): array
    {
        $wpConfigVars = [
            'DB_HOST' => 'DB_HOST',
            'DB_DATABASE' => 'DB_NAME',
            'DB_USERNAME' => 'DB_USER',
            'DB_PASSWORD' => 'DB_PASSWORD',
        ];

        $credentials = [];

        foreach ($wpConfigVars as $key => $wpKey) {
            $process = (new CreateSshConnection)($server)
                ->execute([
                    "sudo grep \"define.*'{$wpKey}'\" /var/www/{$site}/current/wp-config.php /var/www/{$site}/current/public/wp-config.php /var/www/{$site}/current/config/application.php 2>/dev/null | head -1 | sed \"s/.*'[^']*'[^']*'\\([^']*\\)'.*/\\1/\"",
                ]);

            $value = trim($process->getOutput());

            if (empty($value) && $key === 'DB_HOST') {
                $value = '127.0.0.1';
            } elseif (empty($value) && $key !== 'DB_PASSWORD') {
                throw new StepException("Could not fetch {$wpKey} from remote wp-config.php.");
            }

            $credentials[$key] = $value;
        }

        return $credentials;
    }

    public function prepareLocalDatabase(string $name): void
    {
        $identifier = $this->quoteMysqlIdentifier($name);

        Process::fromShellCommandline(
            'mysql -u root --password= -e "SET @@global.time_zone=\'+00:00\'" 2>/dev/null'
        )->run();

        Process::fromShellCommandline(
            'mysql -u root --password= -e '.escapeshellarg("DROP DATABASE IF EXISTS `{$identifier}`").' 2>/dev/null'
        )->run();

        $process = Process::fromShellCommandline(
            'mysql -u root --password= -e '.escapeshellarg("CREATE DATABASE IF NOT EXISTS `{$identifier}` CHARACTER SET utf8 COLLATE utf8_general_ci").' 2>/dev/null'
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Could not create local database: '.trim($process->getErrorOutput()));
        }
    }

    /**
     * Dump the remote database and pipe it straight into the local mysql
     * client. The remote credentials never appear as command-line arguments
     * (on either end) - they're written to a mode-600 temp file on the
     * remote server via a quoted heredoc (so the values are never
     * shell-expanded) and passed to mysqldump via --defaults-extra-file,
     * which keeps them out of `ps` on both machines. The temp file is
     * removed via a trap as soon as the remote script exits, success or not.
     */
    public function importDatabase(array $credentials, string $server): void
    {
        $credentialsFileDelimiter = 'RCKT_'.bin2hex(random_bytes(16));

        $remoteScript = implode(PHP_EOL, [
            'set -euo pipefail',
            'umask 077',
            'CNF=$(mktemp)',
            'trap \'rm -f "$CNF"\' EXIT',
            'cat > "$CNF" <<'."'{$credentialsFileDelimiter}'",
            '[client]',
            'host='.$credentials['DB_HOST'],
            'user='.$credentials['DB_USERNAME'],
            'password='.$credentials['DB_PASSWORD'],
            $credentialsFileDelimiter,
            'sudo mysqldump --defaults-extra-file="$CNF" --max-allowed-packet=512M --no-tablespaces --single-transaction '
                .escapeshellarg($credentials['DB_DATABASE']).' | sudo gzip',
        ]);

        $remoteCommand = (new CreateSshConnection)($server)
            ->addExtraOption('-o ServerAliveInterval=60')
            ->getExecuteCommand($remoteScript);

        $process = Process::fromShellCommandline(
            'set -o pipefail; '.$remoteCommand
            .' | gunzip'
            .' | mysql --max-allowed-packet=512M --user=root --password= --init-command="SET FOREIGN_KEY_CHECKS=0;" '.escapeshellarg($credentials['name'])
        );
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Database import failed: '.trim($process->getErrorOutput()));
        }
    }

    protected function quoteMysqlIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }
}
