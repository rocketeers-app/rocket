<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class GitCloneRepository
{
    use AsAction;

    public function handle($name, $url, ?string $directory = null, ?string $branch = null)
    {
        $directory ??= "/var/www/{$name}";

        if (is_dir("{$directory}/.git")) {
            return;
        }

        $process = new Process(['git', 'clone', ...($branch === null ? [] : ['--branch', $branch]), $url, $directory]);
        $process->setTimeout(300);
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException('Git clone failed: '.ProcessError::message($process));
        }
    }
}
