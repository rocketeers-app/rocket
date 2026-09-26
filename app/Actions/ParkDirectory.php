<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
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
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException("Could not park {$directory}: ".ProcessError::message($process));
        }
    }
}
