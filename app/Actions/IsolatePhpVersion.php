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

        $process = Process::fromShellCommandline(command: "{$herdOrValet} isolate php@{$phpVersion}", cwd: "/var/www/{$name}");

        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
