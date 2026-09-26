<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class RunMigrations
{
    use AsAction;

    public function handle($name, ?string $directory = null)
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = Process::fromShellCommandline(command: "{$herdOrValet} php artisan migrate --force", cwd: $directory ?? "/var/www/{$name}");
        $process->setTimeout(300);
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException('Migrations failed: '.ProcessError::message($process));
        }
    }
}
