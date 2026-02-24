<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class NpmInstall
{
    use AsAction;

    public function handle($name)
    {
        $process = Process::fromShellCommandline(command: 'export NVM_DIR="$HOME/.nvm" && [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" && nvm use && npm install && npm run dev', cwd: "/var/www/{$name}");
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('npm install failed: '.trim($process->getErrorOutput()));
        }
    }
}
