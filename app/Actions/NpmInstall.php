<?php

namespace App\Actions;

use App\Exceptions\StepException;
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
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('npm install failed: '.trim($process->getErrorOutput()));
        }
    }

    public function command(string $directory): string
    {
        if (! file_exists("{$directory}/.nvmrc")) {
            return 'npm install';
        }

        return 'export NVM_DIR="$HOME/.nvm" && [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" && nvm use && npm install';
    }
}
