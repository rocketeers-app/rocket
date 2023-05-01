<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class ValetIsolatePhpVersion
{
    use AsAction;

    public function handle($name, $phpVersion)
    {
        $process = Process::fromShellCommandline("cd /var/www/{$name} && valet isolate php@{$phpVersion}");
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
