<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Spatie\Ssh\Ssh;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

class TailLog extends Command
{
    protected $signature = 'tail:log {site} {--server=}';

    protected $description = 'Tail a Laravel log file on the remote server';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $process = Ssh::create('rocketeer', $server)
            ->disableStrictHostKeyChecking()
            ->execute("ls -t /var/www/{$site}/persistent/storage/logs/laravel-* 2>/dev/null");

        $output = trim($process->getOutput());

        if (empty($output)) {
            $this->error('No log files found.');

            return 1;
        }

        $files = collect(explode("\n", $output))
            ->map(fn ($file) => trim($file))
            ->filter()
            ->values()
            ->all();

        $labels = array_map(fn ($file) => basename($file), $files);

        $selected = select(
            label: 'Which log file?',
            options: array_combine($files, $labels),
        );

        $this->info('Tailing '.basename($selected).'...');

        Ssh::create('rocketeer', $server)
            ->disableStrictHostKeyChecking()
            ->configureProcess(fn (Process $process) => $process->setTty(true))
            ->onOutput(fn ($type, $line) => $this->output->write($line))
            ->execute("tail -f {$selected}");
    }
}
