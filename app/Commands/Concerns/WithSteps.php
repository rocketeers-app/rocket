<?php

namespace App\Commands\Concerns;

use function Laravel\Prompts\spin;

trait WithSteps
{
    protected function step(string $message, callable $callback): mixed
    {
        $result = spin($callback, $message.'...');

        $this->line("  <info>✓</info> {$message}");

        return $result;
    }
}
