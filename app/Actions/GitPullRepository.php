<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class GitPullRepository
{
    use AsAction;

    public function handle()
    {
        $process = new Process(['git', 'pull']);
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
