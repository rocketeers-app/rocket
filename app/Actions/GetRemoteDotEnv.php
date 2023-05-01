<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Ssh\Ssh;

class GetRemoteDotEnv
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $process = Ssh::create('rocketeer', $server ?? $site)
            ->execute("sudo cat /var/www/{$site}/.env");

        return $process->getOutput();
    }
}
