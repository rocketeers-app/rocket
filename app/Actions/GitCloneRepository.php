<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class GitCloneRepository
{
    use AsAction;

    public function handle($name, $url)
    {
        $path = LocalSitePath::for($name);

        if (is_dir("{$path}/.git")) {
            return;
        }

        $process = new Process(['git', 'clone', $url, $path]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Git clone failed: '.trim($process->getErrorOutput()));
        }
    }
}
