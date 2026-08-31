<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

class DetectRemotePhpVersion
{
    use AsAction;

    public function handle($site, $server = null): string
    {
        $process = (new CreateSshConnection)($server ?? $site)
            ->execute('php -r \'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;\'');

        $version = trim($process->getOutput());

        if (! $process->isSuccessful() || ! preg_match('/^\d+\.\d+$/', $version)) {
            throw new StepException("Could not detect the remote PHP version for {$site}. Pass --php explicitly.");
        }

        return $version;
    }
}
