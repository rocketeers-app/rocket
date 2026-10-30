<?php

namespace App\Commands\Concerns;

use App\Exceptions\StepException;
use App\Support\CommandLog;

/**
 * Shows each step of a command as one line, `Importing routine ........ 2s DONE` (or FAIL, letting the error
 * through); silent under --json. With --verbose each step is a line of its own, followed by the commands it runs
 * and their output. A step that may fail without stopping the command goes through attempt(), which shows FAIL
 * and hands back the error instead of throwing it.
 */
trait WithSteps
{
    protected function step(string $message, callable $callback): mixed
    {
        if ($this->quietSteps()) {
            app(CommandLog::class)->step($message);

            return $callback();
        }

        $result = null;

        $this->components->task($message, function () use ($callback, &$result): void {
            $result = $callback();
        });

        return $result;
    }

    protected function attempt(string $message, callable $callback): ?string
    {
        try {
            $this->step($message, $callback);

            return null;
        } catch (StepException $exception) {
            return $exception->getMessage();
        }
    }

    protected function skipStep(string $message, string $reason): void
    {
        if ($this->quietSteps()) {
            app(CommandLog::class)->step("{$message} (skipped: {$reason})");

            return;
        }

        $this->components->twoColumnDetail($message, '<fg=yellow;options=bold>SKIPPED</>');
    }

    private function quietSteps(): bool
    {
        return (method_exists($this, 'wantsJson') && $this->wantsJson()) || app(CommandLog::class)->enabled();
    }
}
