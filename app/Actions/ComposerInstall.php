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

        Process::fromShellCommandline(command: "{$herdOrValet} use", cwd: "/var/www/{$name}")
            ->setTimeout(30)
            ->run();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} composer install", cwd: "/var/www/{$name}");
        $process->setTimeout(300);
        $process->run();
    }
}
