<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class IsolatePhpVersion
{
    use AsAction;

    public function handle($name, $phpVersion)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline("cd /var/www/{$name} && {$herdOrValet} isolate php@{$phpVersion}");
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
