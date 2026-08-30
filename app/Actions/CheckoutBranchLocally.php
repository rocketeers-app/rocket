<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class CheckoutBranchLocally
{
    use AsAction;

    public function handle($name, $branch)
    {
        $process = new Process(['git', 'checkout', $branch], cwd: "/var/www/{$name}");
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException("Could not checkout branch {$branch}: ".trim($process->getErrorOutput()));
        }
    }
}
