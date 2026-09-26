<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class GitCloneRepository
{
    use AsAction;

    public function handle($name, $url)
    {
        if (is_dir("/var/www/{$name}/.git")) {
            return;
        }

        $process = new Process(['git', 'clone', $url, '/var/www/'.$name]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Git clone failed: '.trim($process->getErrorOutput()));
        }
    }
}
