<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

class GetRepositoryUrl
{
    use AsAction;

    public function handle($site, $server = null): string
    {
        $process = (new CreateSshConnection)($server ?? $site)
            ->execute("sudo git --work-tree=/var/www/{$site}/current --git-dir=/var/www/{$site}/current/.git config --get remote.origin.url");

        $url = trim($process->getOutput());

        if (empty($url)) {
            throw new StepException("Could not fetch repository URL for {$site}.");
        }

        return $url;
    }
}
