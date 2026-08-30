<?php

namespace App\Commands\Concerns;

use App\Exceptions\StepException;
use Symfony\Component\Console\Helper\ProgressBar;

trait WithSteps
{
    protected ?ProgressBar $progressBar = null;

    protected int $declaredSteps = 0;

    protected int $completedSteps = 0;

    protected function startProgress(int $totalSteps): void
    {
        $this->declaredSteps = $totalSteps;
        $this->completedSteps = 0;

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
            $this->finishProgress(verify: false);
            $this->newLine();
            $this->error($e->getMessage());

            exit(1);
        }

        $this->completedSteps++;
        $this->progressBar->advance();

        return $result;
    }

    /**
     * $verify is false when finishProgress() is called from step()'s own
     * failure path, where completedSteps is expected to fall short of
     * declaredSteps because the command is aborting early.
     */
    protected function finishProgress(bool $verify = true): void
    {
        $this->progressBar->setMessage('Done!');
        $this->progressBar->finish();
        $this->newLine();

        if ($verify && $this->completedSteps !== $this->declaredSteps) {
            $this->warn(
                "startProgress({$this->declaredSteps}) doesn't match the {$this->completedSteps} step() calls that actually ran - update the declared total."
            );
        }
    }
}
