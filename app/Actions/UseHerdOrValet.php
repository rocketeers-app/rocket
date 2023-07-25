<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class UseHerdOrValet
{
    use AsAction;

    public function handle()
    {
        $process = Process::fromShellCommandline('(type herd > /dev/null && echo `which herd`) || (type valet > /dev/null && echo `which valet`)');
        $process->setTty(Process::isTtySupported());
        $process->run();

        return trim($process->getOutput());
    }
}
