<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class RsyncSite
{
    use AsAction;

    public function handle($name, $site, $server)
    {
        $process = Process::fromShellCommandline(
            'rsync -rlptz --delete'
            .' --exclude=.env'
            .' --exclude=node_modules'
            .' --exclude=vendor'
            .' --exclude=storage'
            .' -e "ssh -o StrictHostKeyChecking=accept-new"'
            .' rocketeer@'.$server.':/var/www/'.$site.'/current/'
            .' /var/www/'.$name.'/'
        );

        $process->setTimeout(600);
        $process->run();
    }
}
