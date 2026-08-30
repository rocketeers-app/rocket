<?php

namespace App\Commands\Concerns;

trait ConfirmsDestructiveAction
{
    /**
     * Prompts before a destructive operation (dropping a local database,
     * rsync --delete, ...), skipped when --force is passed. Defaults to
     * "no" so a non-interactive run without --force aborts safely instead
     * of hanging or silently proceeding.
     */
    protected function confirmDestructiveAction(string $question): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm($question, false);
    }
}
