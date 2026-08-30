<?php

namespace App\Actions;

use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class UnsecureSite
{
    use AsAction;

    /**
     * Best-effort: a site that was never secured makes herd/valet unsecure
     * exit non-zero, which shouldn't block the rest of teardown - unlike
     * every other Action here, failures are silently ignored.
     */
    public function handle($name): void
    {
        $herdOrValet = (new UseHerdOrValet)();

        $process = new Process([$herdOrValet, 'unsecure', $name], cwd: LocalSitePath::for($name));
        $process->setTimeout(60);
        $process->run();
    }
}
