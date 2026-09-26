<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/** Parks a directory in Herd (or Valet), so every directory in it is served as https://{directory}.test. */
class ParkDirectory
{
    use AsAction;

    public function handle(string $directory): void
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} park", cwd: $directory);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException("Could not park {$directory}: ".trim($process->getErrorOutput()));
        }
    }
}
