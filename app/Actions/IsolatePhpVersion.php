<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class IsolatePhpVersion
{
    use AsAction;

    public function handle($name, $phpVersion, ?string $directory = null)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} isolate php@{$phpVersion}", cwd: $directory ?? "/var/www/{$name}");
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException("Could not isolate PHP {$phpVersion}: ".trim($process->getErrorOutput()));
        }
    }
}
