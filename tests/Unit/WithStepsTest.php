<?php

use App\Commands\Concerns\WithSteps;
use App\Exceptions\StepException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * WithSteps is a trait meant for a real Illuminate\Console\Command, so it's
 * exercised through small throwaway commands run directly (setLaravel() +
 * run()) rather than via reflection, to keep the actual step()/
 * runWithSteps()/finishProgress() interplay under test instead of just its
 * pieces in isolation.
 */
function runStepsCommand(Command $command, $app): array
{
    $command->setLaravel($app);
    $output = new BufferedOutput;
    $exitCode = $command->run(new ArrayInput([]), $output);

    return [$exitCode, $output->fetch()];
}

it('returns Command::FAILURE and a clean message for a StepException, without a stack trace', function () {
    $command = new class extends Command
    {
        use WithSteps;

        protected $signature = 'test:steps-step-exception';

        public function handle()
        {
            return $this->runWithSteps(function () {
                $this->startProgress(1);
                $this->step('Doing a thing', function () {
                    throw new StepException('Something specific went wrong.');
                });
                $this->finishProgress();
            });
        }
    };

    [$exitCode, $output] = runStepsCommand($command, $this->app);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Something specific went wrong.')
        ->and($output)->not->toContain('Stack trace')
        ->and($output)->not->toContain('#0 ');
});

it('returns Command::FAILURE for a non-StepException Throwable too, labelled as unexpected', function () {
    $command = new class extends Command
    {
        use WithSteps;

        protected $signature = 'test:steps-generic-exception';

        public function handle()
        {
            return $this->runWithSteps(function () {
                $this->startProgress(1);
                $this->step('Doing a thing', function () {
                    throw new RuntimeException('Something broke unexpectedly.');
                });
                $this->finishProgress();
            });
        }
    };

    [$exitCode, $output] = runStepsCommand($command, $this->app);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Unexpected error')
        ->and($output)->toContain('Something broke unexpectedly.');
});

it('returns Command::SUCCESS and no warning when the declared step count matches', function () {
    $command = new class extends Command
    {
        use WithSteps;

        protected $signature = 'test:steps-matching-count';

        public function handle()
        {
            return $this->runWithSteps(function () {
                $this->startProgress(2);
                $this->step('First', fn () => null);
                $this->step('Second', fn () => null);
                $this->finishProgress();
            });
        }
    };

    [$exitCode, $output] = runStepsCommand($command, $this->app);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->not->toContain("doesn't match");
});

it('warns when the declared step count does not match how many steps actually ran', function () {
    $command = new class extends Command
    {
        use WithSteps;

        protected $signature = 'test:steps-mismatched-count';

        public function handle()
        {
            return $this->runWithSteps(function () {
                $this->startProgress(3);
                $this->step('Only one step actually runs', fn () => null);
                $this->finishProgress();
            });
        }
    };

    [$exitCode, $output] = runStepsCommand($command, $this->app);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('startProgress(3)')
        ->and($output)->toContain("doesn't match");
});
