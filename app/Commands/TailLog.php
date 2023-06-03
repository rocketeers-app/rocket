<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Spatie\Ssh\Ssh;
use Symfony\Component\Process\Process;

class TailLog extends Command
{
    protected $signature = 'tail:log {site} {--server=}';

    protected $description = 'Pull env file';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $process = Ssh::create('rocketeer', $server ?? $site)
            ->execute("cd /var/www/{$site}/persistent/storage/logs && ls -t laravel-* | head -1");

        $latestLogFile = trim($process->getOutput());

        $process = Ssh::create('rocketeer', $server)
            ->configureProcess(fn (Process $process) => $process->setTty(true))
            ->onOutput(function ($type, $line) {
                $this->handleClearOption();

                $this->output->write($line);
            })
            ->execute([
                "tail -f /var/www/{$site}/persistent/storage/logs/{$latestLogFile}",
            ]);
    }
}
