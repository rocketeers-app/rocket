<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/** The URL Herd or Valet serves a directory at: its name on their TLD, over https when the site is secured. */
class FindLocalSiteUrl
{
    use AsAction;

    public function handle(string $directory): string
    {
        $tool = rescue(fn (): string => (new UseHerdOrValet)(), report: false)
            ?? throw new StepException('Neither Herd nor Valet was found to tell the URL of this site. Pass it with --url.');

        $name = Str::lower(basename($directory));
        $tld = trim($this->output([$tool, 'tld'], $directory)) ?: 'test';
        $host = "{$name}.{$tld}";
        $secured = str_contains($this->output([$tool, 'secured'], $directory), $host);

        return ($secured ? 'https://' : 'http://').$host;
    }

    private function output(array $command, string $directory): string
    {
        $process = new Process($command, $directory);
        $process->setTimeout(60);
        app(CommandLog::class)->run($process);

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
