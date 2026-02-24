<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class ChangeWorkingDirectory
{
    use AsAction;

    public function handle($name)
    {
        $process = Process::fromShellCommandline(command: "cd /var/www/{$name}");
        $process->run();
    }
}
