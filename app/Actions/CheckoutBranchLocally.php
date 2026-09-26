<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class CheckoutBranchLocally
{
    use AsAction;

    public function handle($name, $branch, ?string $directory = null)
    {
        $process = Process::fromShellCommandline(command: "git checkout {$branch}", cwd: $directory ?? "/var/www/{$name}");
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException("Could not checkout branch {$branch}: ".trim($process->getErrorOutput()));
        }
    }
}
