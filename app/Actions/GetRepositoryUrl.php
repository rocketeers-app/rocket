<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Ssh\Ssh;

class GetRepositoryUrl
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $process = Ssh::create('rocketeer', $server ?? $site)
        ->execute("cd /var/www/{$site}/current && git config --get remote.origin.url");

        return trim($process->getOutput());
    }
}
