<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;
use Phar;
use Symfony\Component\Process\Process;

/**
 * Replaces the Rocket PHAR with the build of a version: downloads it next to the PHAR, checks that it runs as
 * that version, then moves it in place. Nothing is replaced when any of that fails.
 */
class InstallRocketVersion
{
    use AsAction;

    public function pharPath(): string
    {
        $phar = Phar::running(false);

        if ($phar === '') {
            throw new StepException('Only an installed Rocket can update itself. Build one with `php rocket app:build`.');
        }

        if (! is_writable($phar) || ! is_writable(dirname($phar))) {
            throw new StepException("Cannot write to {$phar}. Run `rocket self-update` as a user that can.");
        }

        return $phar;
    }

    public function download(string $version, string $phar): string
    {
        $download = dirname($phar).'/.'.basename($phar).".{$version}.download";
        $url = 'https://raw.githubusercontent.com/'.LatestRocketVersion::REPOSITORY."/v{$version}/builds/rocket";

        try {
            $response = Http::withUserAgent('rocket-cli/'.config('app.version'))->timeout(300)->sink($download)->get($url);
        } catch (ConnectionException) {
            $this->discard($download);

            throw new StepException("Could not download Rocket {$version} from GitHub.");
        }

        if (! $response->successful()) {
            $this->discard($download);

            throw new StepException("Could not download Rocket {$version}: GitHub answered HTTP {$response->status()}.");
        }

        if ($this->versionOf($download) !== "Rocket {$version}") {
            $this->discard($download);

            throw new StepException("The download does not run as Rocket {$version}, so nothing was replaced.");
        }

        return $download;
    }

    public function replace(string $download, string $phar): void
    {
        chmod($download, fileperms($phar) & 0777);

        if (! rename($download, $phar)) {
            $this->discard($download);

            throw new StepException("Could not replace {$phar}.");
        }
    }

    private function versionOf(string $file): string
    {
        $process = new Process([PHP_BINARY, $file, '--version', '--no-ansi']);
        $process->setTimeout(60);
        $process->run();

        return trim($process->getOutput());
    }

    private function discard(string $download): void
    {
        if (file_exists($download)) {
            unlink($download);
        }
    }
}
