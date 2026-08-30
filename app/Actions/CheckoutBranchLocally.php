<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class CheckoutBranchLocally
{
    use AsAction;

    public function handle($name, $branch)
    {
        $process = new Process(['git', 'checkout', $branch], cwd: LocalSitePath::for($name));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException("Could not checkout branch {$branch}: ".trim($process->getErrorOutput()));
        }
    }
}
