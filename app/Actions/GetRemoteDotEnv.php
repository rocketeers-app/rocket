<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class GetRemoteDotEnv
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $process = (new CreateSshConnection)($server ?? $site)
            ->execute("sudo cat /var/www/{$site}/.env");

        return $process->getOutput();
    }
}
