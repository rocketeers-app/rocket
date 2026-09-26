<?php

namespace App\Support;

use Spatie\Ssh\Ssh;
use Symfony\Component\Process\Process;

/** A Spatie SSH connection that runs its commands through the CommandLog, so --verbose shows them. */
class LoggedSsh extends Ssh
{
    private int $processTimeout = 0;

    private bool $showOutput = true;

    private ?string $label = null;

    public function setTimeout(int $timeout): self
    {
        $this->processTimeout = $timeout;

        return parent::setTimeout($timeout);
    }

    public function hideOutput(): self
    {
        $this->showOutput = false;

        return $this;
    }

    public function execute($command): Process
    {
        $this->label = "ssh {$this->user}@{$this->host}: ".implode('; ', array_map('trim', (array) $command));

        return parent::execute($command);
    }

    protected function run(string $command, string $method = 'run'): Process
    {
        if ($method !== 'run') {
            return parent::run($command, $method);
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout($this->processTimeout);
        ($this->processConfigurationClosure)($process);

        return app(CommandLog::class)->run($process, $this->showOutput, $this->onOutput, $this->label);
    }
}
