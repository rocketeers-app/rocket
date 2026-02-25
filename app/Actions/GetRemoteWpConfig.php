<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

class GetRemoteWpConfig
{
    use AsAction;

    public function handle($site, $server = null)
    {
        $process = (new CreateSshConnection)($server ?? $site)
            ->execute("sudo cat /var/www/{$site}/current/wp-config.php 2>/dev/null || sudo cat /var/www/{$site}/current/public/wp-config.php 2>/dev/null");

        $output = $process->getOutput();

        if (! $process->isSuccessful() || empty(trim($output))) {
            throw new StepException("Could not fetch wp-config.php from remote server for {$site}.");
        }

        return $output;
    }
}
