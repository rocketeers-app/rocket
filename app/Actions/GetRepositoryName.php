<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Ssh\Ssh;

class GetRepositoryName
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $process = Ssh::create('rocketeer', $server ?? $site)
            ->execute("sudo git --work-tree=/var/www/{$site}/current --git-dir=/var/www/{$site}/current/.git config --get remote.origin.url");

        $url = trim($process->getOutput());
        $name = str_replace('.git', '', last(explode('/', $url)));

        return $name;
    }
}
