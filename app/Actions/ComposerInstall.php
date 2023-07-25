<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class ComposerInstall
{
    use AsAction;

    public function handle($name)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} composer install", cwd: "/var/www/{$name}");
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
