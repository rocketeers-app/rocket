<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class SecureSite
{
    use AsAction;

    public function handle($name, ?string $directory = null)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} secure {$name}", cwd: $directory ?? "/var/www/{$name}");
        $process->setTimeout(300);
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException('Could not secure site: '.ProcessError::message($process));
        }
    }
}
