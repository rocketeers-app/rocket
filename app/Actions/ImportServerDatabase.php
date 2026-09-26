<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\Databases;
use Dotenv\Dotenv;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/**
 * Streams one database from the server it lives on into a local database of the same engine.
 * PostgreSQL dumps as the postgres superuser; MySQL as root over the socket, else as the database
 * user the environment's env file (or wp-config) names, read as the environment's own user.
 */
class ImportServerDatabase
{
    use AsAction;

    private const string SSH = 'ssh -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR -o ServerAliveInterval=60';

    /**
     * @param  array<string, mixed>  $database
     * @param  array<int, string>  $credentialHosts
     */
    public function handle(array $database, string $environment, array $credentialHosts, string $localName, ?string $directory = null): void
    {
        $engine = Databases::engine($database);
        $host = Databases::host($database);

        if ($engine === null || $host === null) {
            throw new StepException("{$database['name']} is not a MySQL or PostgreSQL database on one of your servers.");
        }

        $dump = $engine === 'pgsql'
            ? $this->postgresDump((string) $database['name'])
            : $this->mysqlDump((string) $database['name'], $this->mysqlCredentials($host, $environment, $credentialHosts, $directory));

        $engine === 'pgsql'
            ? $this->preparePostgres($localName)
            : (new ImportRemoteDatabase)->prepareLocalDatabase($localName);

        $process = Process::fromShellCommandline($this->pipeline($host, $dump, $this->localImport($engine, $localName)));
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException("Importing {$database['name']} failed: ".trim($process->getErrorOutput()));
        }
    }

    public function postgresDump(string $name): string
    {
        return 'sudo -u postgres pg_dump --no-owner --no-acl '.escapeshellarg($name).' | gzip';
    }

    /** @param array{DB_USERNAME: string, DB_PASSWORD: string}|null $credentials */
    public function mysqlDump(string $name, ?array $credentials): string
    {
        $options = '--single-transaction --no-tablespaces --routines --triggers --max-allowed-packet=512M '.escapeshellarg($name);

        if ($credentials === null) {
            return 'sudo mysqldump -u root '.$options.' | gzip';
        }

        return 'MYSQL_PWD='.escapeshellarg($credentials['DB_PASSWORD']).' mysqldump --host=127.0.0.1 --user='.escapeshellarg($credentials['DB_USERNAME']).' '.$options.' | gzip';
    }

    public function localImport(string $engine, string $localName): string
    {
        if ($engine === 'pgsql') {
            return 'psql --quiet --host=127.0.0.1 --username='.escapeshellarg((string) config('rocketeers.local_pgsql_user')).' --dbname='.escapeshellarg($localName);
        }

        return 'mysql --max-allowed-packet=512M --user=root --password= --init-command="SET FOREIGN_KEY_CHECKS=0;" '.escapeshellarg($localName);
    }

    public function pipeline(string $host, string $dump, string $import): string
    {
        return 'set -o pipefail; '.self::SSH.' '.escapeshellarg('rocketeer@'.$host).' '.escapeshellarg($dump).' | gunzip | '.$import;
    }

    private function mysqlCredentials(string $host, string $environment, array $credentialHosts, ?string $directory): ?array
    {
        $root = (new CreateSshConnection)($host)->execute('sudo mysql -u root -e "SELECT 1" >/dev/null 2>&1 && echo yes || echo no');

        if (trim($root->getOutput()) === 'yes') {
            return null;
        }

        foreach (array_values(array_unique([$host, ...$credentialHosts])) as $server) {
            try {
                $credentials = $this->credentialsFrom(app(ReadRemoteEnvFile::class)->handle($server, $environment, $directory));
            } catch (StepException) {
                continue;
            }

            if ($credentials !== null) {
                return $credentials;
            }
        }

        throw new StepException("MySQL on {$host} does not let root in over the socket, and no server of {$environment} has an env file naming its database user.");
    }

    public function credentialsFrom(array $file): ?array
    {
        $values = $file['wordpress'] ? $this->wordPressDefines($file['contents']) : Dotenv::parse($file['contents']);
        $username = $values['DB_USERNAME'] ?? $values['DB_USER'] ?? '';

        if ($username === '') {
            return null;
        }

        return ['DB_USERNAME' => $username, 'DB_PASSWORD' => (string) ($values['DB_PASSWORD'] ?? '')];
    }

    private function wordPressDefines(string $config): array
    {
        preg_match_all('/define\s*\(\s*[\'"](DB_USER|DB_PASSWORD)[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/', $config, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])->all();
    }

    /** @return array<int, string> */
    public function postgresRecreate(string $localName): array
    {
        $identifier = '"'.str_replace('"', '""', $localName).'"';
        $psql = 'psql --quiet --host=127.0.0.1 --username='.escapeshellarg((string) config('rocketeers.local_pgsql_user')).' --dbname=postgres --command=';

        return [
            $psql.escapeshellarg("DROP DATABASE IF EXISTS {$identifier} WITH (FORCE)"),
            $psql.escapeshellarg("CREATE DATABASE {$identifier}"),
        ];
    }

    private function preparePostgres(string $localName): void
    {
        if (Process::fromShellCommandline('command -v psql')->run() !== 0) {
            throw new StepException('psql is not installed. Start PostgreSQL in Herd (Services) or install the PostgreSQL client.');
        }

        foreach ($this->postgresRecreate($localName) as $command) {
            $process = Process::fromShellCommandline($command);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new StepException('Could not create local PostgreSQL database: '.trim($process->getErrorOutput()));
            }
        }
    }
}
