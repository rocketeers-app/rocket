<?php

namespace App\Commands\Concerns;

use App\Exceptions\StepException;
use Symfony\Component\Console\Helper\ProgressBar;

trait WithSteps
{
    protected ?ProgressBar $progressBar = null;

    protected function startProgress(int $totalSteps): void
    {
        ProgressBar::setFormatDefinition('custom', ' %current%/%max% [%bar%] %message%');

        $this->progressBar = $this->output->createProgressBar($totalSteps);
        $this->progressBar->setFormat('custom');
        $this->progressBar->setMessage('Starting...');
        $this->progressBar->start();
    }

    protected function step(string $message, callable $callback): mixed
    {
        $this->progressBar->setMessage($message.'...');
        $this->progressBar->display();

        try {
            $result = $callback();
        } catch (StepException $e) {
            $this->finishProgress();
            $this->newLine();
            $this->error($e->getMessage());

            exit(1);
        }

        $this->progressBar->advance();

        return $result;
    }

    protected function finishProgress(): void
    {
        $this->progressBar->setMessage('Done!');
        $this->progressBar->finish();
        $this->newLine();
    }
}
