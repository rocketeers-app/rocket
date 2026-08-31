<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class RsyncSite
{
    use AsAction;

    /**
     * $dryRun runs rsync with --dry-run --verbose so the caller can show
     * exactly what would be changed/deleted locally without touching
     * anything - see the sync {--dry-run} option.
     */
    public function handle($name, $site, $server, bool $dryRun = false): string
    {
        $process = new Process([
            'rsync', '-rlptz', '--delete',
            ...($dryRun ? ['--dry-run', '--verbose'] : []),
            '--exclude=.env',
            '--exclude=node_modules',
            '--exclude=vendor',
            '--exclude=storage',
            '-e', 'ssh -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR',
            "rocketeer@{$server}:/var/www/{$site}/current/",
            LocalSitePath::for($name).'/',
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Rsync failed: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
