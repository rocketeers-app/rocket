<?php

namespace App\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * With --verbose, prints every step and every command Rocket runs, and streams what the command prints. Secrets
 * handed to hide() are masked, and output that holds secrets (like a remote env file) can be left out.
 */
class CommandLog
{
    private ?OutputInterface $output = null;

    private array $secrets = [];

    public function enable(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function enabled(): bool
    {
        return $this->output !== null;
    }

    public function hide(string $secret): void
    {
        if ($secret !== '') {
            $this->secrets[] = $secret;
        }
    }

    public function step(string $message): void
    {
        $this->output?->writeln("  <fg=cyan>→</> {$message}");
    }

    public function run(Process $process, bool $showOutput = true, ?callable $callback = null, ?string $label = null): Process
    {
        if ($this->output === null) {
            $process->run($callback);

            return $process;
        }

        $this->output->writeln('    <fg=gray>$ '.$this->escape($label ?? $process->getCommandLine().'  (in '.$process->getWorkingDirectory().')').'</>');

        $process->run(function (string $type, string $buffer) use ($showOutput, $callback): void {
            if ($callback !== null) {
                $callback($type, $buffer);
            }

            if ($showOutput) {
                $this->stream($buffer);
            }
        });

        if (! $showOutput) {
            $this->output->writeln('    <fg=gray>│ (output hidden)</>');
        }

        if (! $process->isSuccessful()) {
            $this->output->writeln("    <fg=red>│ exit code {$process->getExitCode()}</>");
        }

        return $process;
    }

    private function stream(string $buffer): void
    {
        foreach (preg_split('/\r\n|\r|\n/', rtrim($buffer)) as $line) {
            if (trim($line) !== '') {
                $this->output?->writeln('    <fg=gray>│</> '.$this->escape($line));
            }
        }
    }

    private function escape(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace([str_replace("'", "'\\''", $secret), $secret], '***', $text);
        }

        return OutputFormatter::escape($text);
    }
}
