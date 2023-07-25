<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class RunMigrations
{
    use AsAction;

    public function handle($name)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline("cd /var/www/{$name} && {$herdOrValet} php artisan migrate --force");
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
