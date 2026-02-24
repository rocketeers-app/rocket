<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Spatie\Ssh\Ssh;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

class TailLog extends Command
{
    protected $signature = 'tail {site} {--server=}';

    protected $description = 'Tail a log file on the remote server';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $process = Ssh::create('rocketeer', $server)
            ->disableStrictHostKeyChecking()
            ->execute("find /var/www/{$site}/persistent/storage/logs /var/www/{$site}/logs -type f -name '*.log' 2>/dev/null | sort -r");

        $output = trim($process->getOutput());

        if (empty($output)) {
            $this->error('No log files found.');

            return 1;
        }

        $files = collect(explode("\n", $output))
            ->map(fn ($file) => trim($file))
            ->filter(fn ($file) => str_ends_with($file, '.log'))
            ->values()
            ->all();

        if (empty($files)) {
            $this->error('No log files found.');

            return 1;
        }

        $basePaths = [
            "/var/www/{$site}/persistent/storage/logs/",
            "/var/www/{$site}/logs/",
        ];

        $labels = array_map(function ($file) use ($basePaths) {
            foreach ($basePaths as $base) {
                if (str_starts_with($file, $base)) {
                    return str_replace($base, '', $file);
                }
            }

            return basename($file);
        }, $files);

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
