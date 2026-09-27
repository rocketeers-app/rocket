<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Draws a deployment as it runs, one poll at a time. Every finished step becomes a permanent task line
 * (`[web-1] Running migrations ........ 4s DONE`); in a terminal the steps running right now stay below them as
 * RUNNING lines that are redrawn on each poll. Without a terminal (CI, pipes) only finished steps are printed.
 * The server is named in front of a step only when the environment has more than one.
 */
class DeploymentRenderer
{
    private ?ConsoleSectionOutput $live = null;

    private OutputInterface $log;

    private array $printed = [];

    private bool $started = false;

    private bool $announcedQueue = false;

    private int $labelWidth = 0;

    public function __construct(OutputInterface $output, private readonly string $environment, bool $live)
    {
        if ($live && $output instanceof ConsoleOutputInterface) {
            $this->log = $output->section();
            $this->live = $output->section();
        } else {
            $this->log = $output;
        }
    }

    public function update(array $deployment, array $steps, array $servers): void
    {
        if (! $this->started) {
            $this->start($deployment, $servers);
        }

        $queued = blank($deployment['started_at'] ?? null) && $steps === [] && ! $this->isFinished($deployment);

        if ($queued && ! $this->announcedQueue && $this->live === null) {
            $this->log->writeln('  <fg=gray>Waiting for the deployment before it to finish</>');
            $this->announcedQueue = true;
        }

        foreach ($steps as $step) {
            if (($step['status'] ?? 'running') !== 'running' && ! isset($this->printed[$step['id']])) {
                $this->log->writeln($this->line($step, $step['status'] === 'success' ? '<fg=green;options=bold>DONE</>' : '<fg=red;options=bold>FAIL</>', $this->duration((float) ($step['duration'] ?? 0))));
                $this->printed[$step['id']] = true;
            }
        }

        $this->live?->overwrite($this->liveLines($steps, $queued));
    }

    public function finish(): void
    {
        $this->live?->clear();
    }

    public function isFinished(array $deployment): bool
    {
        return filled($deployment['completed_at'] ?? null) || filled($deployment['failed_at'] ?? null) || filled($deployment['cancelled_at'] ?? null);
    }

    private function start(array $deployment, array $servers): void
    {
        $names = array_values(array_filter(array_map(fn (array $server): string => (string) ($server['name'] ?? ''), $servers)));
        $this->labelWidth = count($names) > 1 ? max(array_map('mb_strwidth', $names)) + 3 : 0;

        $details = array_values(array_filter([$deployment['branch'] ?? null, $deployment['short_hash'] ?? null]));

        $this->log->writeln('');
        $this->log->writeln("  Deploying <fg=cyan>{$this->environment}</>"
            .($details === [] ? '' : ' <fg=gray>('.implode(' · ', $details).')</>')
            .($names === [] ? '' : ' to '.implode(', ', $names)));
        $this->log->writeln('');

        $this->started = true;
    }

    private function liveLines(array $steps, bool $queued): array
    {
        if ($queued) {
            return ['  <fg=gray>Waiting for the deployment before it to finish</>'];
        }

        $now = CarbonImmutable::now();

        return array_values(array_map(
            fn (array $step): string => $this->line($step, '<fg=yellow;options=bold>RUNNING</>', $this->duration(max(0, $now->getTimestamp() - CarbonImmutable::parse($step['started_at'] ?? $now)->getTimestamp()))),
            array_filter($steps, fn (array $step): bool => ($step['status'] ?? 'running') === 'running'),
        ));
    }

    public static function taskLine(string $title, string $state, string $time = ''): string
    {
        $stateWidth = mb_strwidth(strip_tags($state));
        $dots = max(min((new Terminal)->getWidth(), 150) - 7 - mb_strwidth($title) - mb_strwidth($time) - $stateWidth, 1);

        return '  '.OutputFormatter::escape($title).' <fg=gray>'.str_repeat('.', $dots).($time === '' ? '' : ' '.$time).'</> '.$state;
    }

    private function line(array $step, string $state, string $time): string
    {
        $label = $this->labelWidth === 0 ? '' : str_pad('['.($step['server']['name'] ?? 'all').']', $this->labelWidth);

        return self::taskLine($label.$step['title'], $state, $time);
    }

    private function duration(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000).'ms';
        }

        if ($seconds < 60) {
            return round($seconds).'s';
        }

        return intdiv((int) round($seconds), 60).'m '.((int) round($seconds) % 60).'s';
    }
}
