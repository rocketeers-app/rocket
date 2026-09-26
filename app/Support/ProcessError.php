<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** The message of a failed process on one line: its error output, else its output (artisan and nvm print errors there), without a stack trace. */
class ProcessError
{
    public static function message(Process $process): string
    {
        $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());
        $message = (string) preg_replace('/\s*\n\s*/', ' ', trim(Str::before($output, "\n  at ")));

        return $message === '' ? "exit code {$process->getExitCode()} without output" : Str::limit($message, 1000);
    }
}
