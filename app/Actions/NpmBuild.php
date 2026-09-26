<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/** Builds the frontend assets with the first of the build, prod or production scripts the project defines. */
class NpmBuild
{
    use AsAction;

    private const SCRIPTS = ['build', 'prod', 'production'];

    public function handle(string $directory): ?string
    {
        $script = $this->script($directory);

        if ($script === null) {
            return null;
        }

        $process = Process::fromShellCommandline(command: app(NpmInstall::class)->command($directory, "npm run {$script}"), cwd: $directory);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException("npm run {$script} failed: ".trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $script;
    }

    public function script(string $directory): ?string
    {
        $package = json_decode((string) @file_get_contents("{$directory}/package.json"), true);
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];

        foreach (self::SCRIPTS as $script) {
            if (filled($scripts[$script] ?? null)) {
                return $script;
            }
        }

        return null;
    }
}
