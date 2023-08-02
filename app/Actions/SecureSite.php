<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class SecureSite
{
    use AsAction;

    public function handle($name)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} secure {$name}", cwd: "/var/www/{$name}");
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
