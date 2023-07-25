<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class UseHerdOrValet
{
    use AsAction;

    public function handle()
    {
        $process = Process::fromShellCommandline('(type herd > /dev/null && echo "herd") || (type valet > /dev/null && echo "valet")');
        $process->setTty(Process::isTtySupported());
        $process->run();

        return trim($process->getOutput());
    }
}
