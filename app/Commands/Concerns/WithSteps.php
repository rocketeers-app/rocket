<?php

namespace App\Commands\Concerns;

use App\Support\CommandLog;

/**
 * Shows each step of a command as one line, `Importing routine ........ 2s DONE` (or FAIL, letting the error
 * through); silent under --json. With --verbose each step is a line of its own, followed by the commands it runs
 * and their output.
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

    private function quietSteps(): bool
    {
        return (method_exists($this, 'wantsJson') && $this->wantsJson()) || app(CommandLog::class)->enabled();
    }
}
