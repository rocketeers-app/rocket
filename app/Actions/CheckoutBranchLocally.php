<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class CheckoutBranchLocally
{
    use AsAction;

    public function handle($name, $branch)
    {
        $process = Process::fromShellCommandline(command: "git checkout {$branch}", cwd: '/var/www');
        $process->setTty(Process::isTtySupported());
        $process->run();
    }
}
