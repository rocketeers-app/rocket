<?php

namespace App\Commands\Concerns;

use App\Exceptions\StepException;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

trait WithSteps
{
    protected ?ProgressBar $progressBar = null;

    protected int $declaredSteps = 0;

    protected int $completedSteps = 0;

    /**
     * Runs $body (a command's actual work) and turns a StepException - or
     * any other uncaught Throwable - into a clean single-line error and a
     * normal Command::FAILURE return, instead of an uncaught fatal with a
     * raw stack trace. Also avoids exit(1), which previously made these
     * commands impossible to exercise from a test (it kills the process
     * running them, PHPUnit included).
     */
    protected function runWithSteps(callable $body): int
    {
        try {
            return $body() ?? self::SUCCESS;
        } catch (Throwable $e) {
            if ($this->progressBar !== null) {
                $this->finishProgress(verify: false);
            }

            $this->newLine();
            $this->error($e instanceof StepException ? $e->getMessage() : 'Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

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

        $result = $callback();

        $this->completedSteps++;
        $this->progressBar->advance();

        return $result;
    }

    /**
     * $verify is false when finishProgress() is called from runWithSteps()'s
     * own failure path, where completedSteps is expected to fall short of
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
