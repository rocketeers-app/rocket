<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class NpmInstall
{
    use AsAction;

    public function handle($name)
    {
        $process = Process::fromShellCommandline(command: "nvm use && npm install && npm run dev", cwd: "/var/www/{$name}");
        $process->setTimeout(300);
        $process->run();
    }
}
