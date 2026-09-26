<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class GetCurrentSshConfig
{
    use AsAction;

    public function handle(): string
    {
        $process = Process::fromShellCommandline('cat ~/.ssh/config');
        $process->run();

        return trim($process->getOutput());
    }
}
