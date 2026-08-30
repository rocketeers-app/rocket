<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class SecureSite
{
    use AsAction;

    public function handle($name)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = new Process([$herdOrValet, 'secure', $name], cwd: LocalSitePath::for($name));
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Could not secure site: '.trim($process->getErrorOutput()));
        }
    }
}
