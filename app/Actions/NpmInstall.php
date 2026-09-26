<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class NpmInstall
{
    use AsAction;

    public function handle($name, ?string $directory = null)
    {
        $directory ??= "/var/www/{$name}";

        $process = Process::fromShellCommandline(command: $this->command($directory), cwd: $directory);
        $process->setTimeout(600);
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException('npm install failed: '.ProcessError::message($process));
        }
    }

    public function command(string $directory, string $npm = 'npm install'): string
    {
        if (! $this->pinsNodeVersion($directory)) {
            return $npm;
        }

        [$nvmDirectory, $script] = $this->nvm() ?? throw new StepException('This project pins a Node version in .nvmrc, but nvm was not found. Install nvm, or Node through Herd.');

        return 'export NVM_DIR='.escapeshellarg($nvmDirectory).' && . '.escapeshellarg($script).' && { nvm use || nvm install; } && '.$npm;
    }

    public function nvm(): ?array
    {
        $home = rtrim((string) getenv('HOME'), '/');
        $nvmDirectory = getenv('NVM_DIR') ?: null;

        return collect([
            $nvmDirectory === null ? null : [$nvmDirectory, "{$nvmDirectory}/nvm.sh"],
            ["{$home}/Library/Application Support/Herd/config/nvm", "{$home}/Library/Application Support/Herd/config/nvm/nvm.sh"],
            ["{$home}/.nvm", "{$home}/.nvm/nvm.sh"],
            ["{$home}/.nvm", '/opt/homebrew/opt/nvm/nvm.sh'],
            ["{$home}/.nvm", '/usr/local/opt/nvm/nvm.sh'],
        ])->filter()->first(fn (array $candidate): bool => is_file($candidate[1]));
    }

    private function pinsNodeVersion(string $directory): bool
    {
        while (! file_exists("{$directory}/.nvmrc")) {
            if (dirname($directory) === $directory) {
                return false;
            }

            $directory = dirname($directory);
        }

        return true;
    }
}
