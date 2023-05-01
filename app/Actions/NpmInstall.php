<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class NpmInstall
{
    use AsAction;

    public function handle($name)
    {
        $process = Process::fromShellCommandline("cd /var/www/{$name} && npm install && npm run dev");
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
