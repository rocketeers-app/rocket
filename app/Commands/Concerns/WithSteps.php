<?php

namespace App\Commands\Concerns;

use Symfony\Component\Console\Helper\ProgressBar;

trait WithSteps
{
    protected ?ProgressBar $progressBar = null;

    protected array $steps = [];

    protected function registerSteps(array $steps): void
    {
        $this->steps = $steps;

        ProgressBar::setFormatDefinition('custom', ' %current%/%max% [%bar%] %message%');

        $this->progressBar = $this->output->createProgressBar(count($steps));
        $this->progressBar->setFormat('custom');
        $this->progressBar->setMessage('Starting...');
        $this->progressBar->start();
    }

    protected function runSteps(): array
    {
        $results = [];

        foreach ($this->steps as $message => $callback) {
            $this->progressBar->setMessage($message.'...');
            $this->progressBar->display();

            $results[$message] = $callback($results);

            $this->progressBar->advance();
        }

        $this->progressBar->setMessage('Done!');
        $this->progressBar->finish();
        $this->newLine();

        return $results;
    }
}
