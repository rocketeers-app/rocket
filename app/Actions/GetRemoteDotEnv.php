<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

class GetRemoteDotEnv
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $process = (new CreateSshConnection)($server ?? $site)
            ->execute("sudo cat /var/www/{$site}/.env");

        $output = $process->getOutput();

        if (! $process->isSuccessful() || empty(trim($output))) {
            throw new StepException("Could not fetch .env from remote server for {$site}.");
        }

        return $output;
    }
}
