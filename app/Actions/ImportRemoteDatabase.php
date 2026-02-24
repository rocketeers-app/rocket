<?php

namespace App\Actions;

use App\Exceptions\StepException;
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

    public function fetchCredentials($site, $server): array
    {
        $name = (new GetRepositoryName)($site, $server);

        $envVars = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
        $credentials = ['name' => $name];

        foreach ($envVars as $var) {
            $process = (new CreateSshConnection)($server)
                ->execute([
                    'sudo grep "^'.$var.'=" /var/www/'.$site."/current/.env | grep -v -e '^\s*#' | cut -d '=' -f 2-",
                ]);

            $value = trim($process->getOutput());

            if (empty($value) && $var !== 'DB_PASSWORD') {
                throw new StepException("Could not fetch {$var} from remote .env.");
            }

            $credentials[$var] = $value;
        }

        return $credentials;
    }

    public function prepareLocalDatabase(string $name): void
    {
        Process::fromShellCommandline(
            'mysql -u root --password="" -e "SET @@global.time_zone=\'+00:00\'" 2>/dev/null'
        )->run();

        Process::fromShellCommandline(
            "mysql -u root --password='' -e 'DROP DATABASE IF EXISTS `".$name."`' 2>/dev/null"
        )->run();

        $process = Process::fromShellCommandline(
            "mysql -u root --password='' -e 'CREATE DATABASE IF NOT EXISTS `".$name."` CHARACTER SET utf8 COLLATE utf8_general_ci' 2>/dev/null"
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Could not create local database: '.trim($process->getErrorOutput()));
        }
    }

    public function importDatabase(array $credentials, string $server): void
    {
        $process = Process::fromShellCommandline(
            'set -o pipefail; ssh -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR -o ServerAliveInterval=60 rocketeer@'.$server
            .' "sudo mysqldump --max-allowed-packet=512M --host=\''.$credentials['DB_HOST'].'\' --user=\''.$credentials['DB_USERNAME'].'\' --password=\''.$credentials['DB_PASSWORD'].'\' --no-tablespaces --single-transaction \''.$credentials['DB_DATABASE'].'\' | sudo gzip"'
            .' | gunzip'
            .' | mysql --max-allowed-packet=512M --user=root --password= --init-command="SET FOREIGN_KEY_CHECKS=0;" '.$credentials['name']
        );
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Database import failed: '.trim($process->getErrorOutput()));
        }
    }
}
