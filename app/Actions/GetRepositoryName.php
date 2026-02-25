<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class GetRepositoryName
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $process = (new CreateSshConnection)($server ?? $site)
            ->execute("sudo git --work-tree=/var/www/{$site}/current --git-dir=/var/www/{$site}/current/.git config --get remote.origin.url");

        $url = trim($process->getOutput());

        if (empty($url)) {
            return preg_replace('/-[a-z]+$/', '', $site);
        }

        return str_replace('.git', '', last(explode('/', $url)));
    }
}
