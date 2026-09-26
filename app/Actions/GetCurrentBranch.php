<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

class GetCurrentBranch
{
    use AsAction;

    public function handle($site, $server = null): string
    {
        $process = (new CreateSshConnection)($server ?? $site)
            ->execute("sudo git --work-tree=/var/www/{$site}/current --git-dir=/var/www/{$site}/current/.git rev-parse --abbrev-ref HEAD");

        $branch = trim($process->getOutput());

        if (empty($branch)) {
            throw new StepException("Could not fetch current branch for {$site}.");
        }

        return $branch;
    }
}
