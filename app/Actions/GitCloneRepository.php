<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class GitCloneRepository
{
    use AsAction;

    public function handle($name, $url)
    {
        $process = new Process(['git', 'clone', $url, '/var/www/'.$name]);
        $process->setTimeout(300);
        $process->run();
    }
}
