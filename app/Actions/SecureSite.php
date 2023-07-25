<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class SecureSite
{
    use AsAction;

    public function handle($name)
    {
        $process = Process::fromShellCommandline("cd /var/www/{$name} && valet secure {$name}");
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
