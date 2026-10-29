<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/**
 * Gets agent-capture ready to run on this machine: the script and fonts the PHAR carries are written to
 * the capture directory (Node cannot read from a PHAR), and the Playwright version the agent runner image
 * pins is installed there with its Chromium the first time, or again when that version changed. Node is
 * always needed, ffmpeg only for the walkthrough video.
 */
class PrepareCapture
{
    use AsAction;

    public function handle(bool $video = true, bool $install = true): array
    {
        $directory = rtrim((string) config('rocketeers.capture.path'), '/');
        $version = (string) config('rocketeers.capture.playwright_version');

        $this->ensureTool(['node', '--version'], 'Node.js is needed to record a capture. Install it through Herd, nvm or `brew install node`.');

        if ($video) {
            $this->ensureTool(['ffmpeg', '-version'], 'ffmpeg is needed for the walkthrough video. Install it with `brew install ffmpeg`, or pass --screenshots-only.');
        }

        File::ensureDirectoryExists($directory);

        $tools = [
            'directory' => $directory,
            'script' => $this->write("{$directory}/agent-capture.cjs", (string) file_get_contents(base_path('resources/capture/agent-capture.js'))),
            'fonts' => $this->write("{$directory}/fonts.css", (string) file_get_contents(base_path('resources/capture/fonts.css'))),
            'playwright' => "{$directory}/node_modules/playwright",
        ];

        if ($install && ! is_file("{$directory}/.installed-{$version}")) {
            $this->install($directory, $version);
        }

        return $tools;
    }

    private function install(string $directory, string $version): void
    {
        File::delete(File::glob("{$directory}/.installed-*"));

        $this->write("{$directory}/package.json", (string) json_encode(['name' => 'rocket-capture', 'private' => true, 'type' => 'commonjs'], JSON_PRETTY_PRINT));

        $this->run(['npm', 'install', '--no-audit', '--no-fund', '--save-exact', "playwright@{$version}"], $directory, "Installing Playwright {$version} failed");
        $this->run(["{$directory}/node_modules/.bin/playwright", 'install', 'chromium'], $directory, 'Installing Chromium for Playwright failed');

        File::put("{$directory}/.installed-{$version}", (string) time());
    }

    private function ensureTool(array $command, string $missing): void
    {
        $process = new Process($command);
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException($missing);
        }
    }

    private function run(array $command, string $directory, string $failure): void
    {
        $process = new Process($command, $directory);
        $process->setTimeout(900);
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException("{$failure}: ".ProcessError::message($process));
        }
    }

    private function write(string $path, string $contents): string
    {
        if (! is_file($path) || hash_file('xxh3', $path) !== hash('xxh3', $contents)) {
            File::put($path, $contents);
        }

        return $path;
    }
}
