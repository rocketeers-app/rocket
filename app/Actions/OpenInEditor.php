<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Opens a file in your editor and waits until you close it: $VISUAL, else $EDITOR, else vi (nano when there is no vi).
 * The editor gets the terminal, so a terminal editor draws as usual; one that exits with an error stops the edit.
 */
class OpenInEditor
{
    use AsAction;

    public function handle(string $path): void
    {
        $editor = $this->editor();
        $process = Process::fromShellCommandline($editor.' '.escapeshellarg($path))->setTimeout(null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        app(CommandLog::class)->run($process, showOutput: false, label: $editor.' '.basename($path));

        if (! $process->isSuccessful()) {
            throw new StepException("{$editor} exited with code {$process->getExitCode()}; nothing was saved.");
        }
    }

    public function editor(): string
    {
        foreach (['VISUAL', 'EDITOR'] as $variable) {
            $value = trim((string) getenv($variable));

            if ($value !== '') {
                return $value;
            }
        }

        return (new ExecutableFinder)->find('vi') !== null ? 'vi' : 'nano';
    }
}
