<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class ComposerInstall
{
    use AsAction;

    public function handle($name)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} composer install", cwd: "/var/www/{$name}");
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Composer install failed: '.trim($process->getErrorOutput()));
        }
    }
}
