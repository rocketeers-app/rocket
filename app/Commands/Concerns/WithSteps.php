<?php

namespace App\Commands\Concerns;

use App\Exceptions\StepException;
use Symfony\Component\Console\Helper\ProgressBar;

/** A progress bar per command; silent under --json. A failing step closes the bar and lets the error through. */
trait WithSteps
{
    protected ?ProgressBar $progressBar = null;

    protected function startProgress(int $totalSteps): void
    {
        if ($this->quietSteps()) {
            return;
        }

        ProgressBar::setFormatDefinition('custom', ' %current%/%max% [%bar%] %message%');

        $this->progressBar = $this->output->createProgressBar($totalSteps);
        $this->progressBar->setFormat('custom');
        $this->progressBar->setMessage('Starting...');
        $this->progressBar->start();
    }

    protected function step(string $message, callable $callback): mixed
    {
        $this->progressBar?->setMessage($message.'...');
        $this->progressBar?->display();

        try {
            $result = $callback();
        } catch (StepException $exception) {
            $this->progressBar?->clear();
            $this->progressBar = null;

            throw $exception;
        }

        $this->progressBar?->advance();

        return $result;
    }

    protected function finishProgress(): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $this->progressBar->setMessage('Done!');
        $this->progressBar->finish();
        $this->progressBar = null;
        $this->newLine();
    }

    private function quietSteps(): bool
    {
        return method_exists($this, 'wantsJson') && $this->wantsJson();
    }
}
