<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * The message of a failed process on one line: its error output, else its output (artisan and nvm print errors there),
 * without a stack trace. A long one keeps its end, where tools like composer and npm put the reason after their progress.
 */
class ProcessError
{
    private const int LIMIT = 1000;

    public static function message(Process $process): string
    {
        $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());
        $message = (string) preg_replace('/\s*\n\s*/', ' ', trim(Str::before($output, "\n  at ")));

        if ($message === '') {
            return "exit code {$process->getExitCode()} without output";
        }

        return mb_strlen($message) > self::LIMIT ? '…'.ltrim(mb_substr($message, -self::LIMIT)) : $message;
    }
}
