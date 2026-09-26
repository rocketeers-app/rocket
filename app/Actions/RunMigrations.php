<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Illuminate\Support\Str;
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
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Migrations failed: '.$this->errorMessage($process));
        }
    }

    public function errorMessage(Process $process): string
    {
        $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

        return preg_replace('/\s*\n\s*/', ' ', trim(Str::before($output, "\n  at "))) ?: 'no output';
    }
}
