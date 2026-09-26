<?php

namespace App\Commands\Concerns;

use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Support\OutputRenderer;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Gives a command `--json`: stdout then carries nothing but one JSON document — the result, or
 * `{"error": {...}}` with a non-zero exit code — and the command never stops to ask anything.
 */
trait OutputsJson
{
    public function wantsJson(): bool
    {
        return $this->input !== null && $this->input->hasOption('json') && (bool) $this->input->getOption('json');
    }

    public function canPrompt(): bool
    {
        return $this->input->isInteractive()
            && ! $this->wantsJson()
            && ($this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }

    protected function configureUsingFluentDefinition(): void
    {
        parent::configureUsingFluentDefinition();

        $this->addJsonOption();
    }

    protected function specifyParameters(): void
    {
        parent::specifyParameters();

        $this->addJsonOption();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        if ($input->hasOption('json') && $input->getOption('json')) {
            $input->setInteractive(false);
            Prompt::interactive(false);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::execute($input, $output);
        } catch (StepException $exception) {
            return $this->reportFailure($exception);
        } catch (Throwable $exception) {
            if (! $this->wantsJson()) {
                throw $exception;
            }

            return $this->reportFailure(new StepException($exception->getMessage()));
        }
    }

    protected function emitJson(mixed $data): int
    {
        app(OutputRenderer::class)->json($this, $data);

        return self::SUCCESS;
    }

    protected function reportFailure(StepException $exception): int
    {
        if ($this->wantsJson()) {
            app(OutputRenderer::class)->json($this, $exception instanceof ApiException ? $exception->toArray() : ['error' => ['message' => $exception->getMessage()]]);

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->error($exception->getMessage());

        if ($exception instanceof ApiException) {
            foreach ($exception->errors as $field => $messages) {
                $this->components->twoColumnDetail("<fg=red>{$field}</>", implode(' ', $messages));
            }
        }

        return self::FAILURE;
    }

    private function addJsonOption(): void
    {
        if (! $this->getDefinition()->hasOption('json')) {
            $this->addOption('json', null, InputOption::VALUE_NONE, 'Print only JSON to stdout (implies --no-interaction)');
        }
    }
}
