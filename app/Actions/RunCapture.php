<?php

namespace App\Actions;

use App\Support\CommandLog;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/**
 * Runs the agent-capture script against a local site with the same flags as in the agent runner, pointing it at
 * this machine's Playwright, fonts and output directory. What it prints is handed to $echo as it comes.
 */
class RunCapture
{
    use AsAction;

    public function handle(array $tools, array $arguments, ?string $url = null, ?string $output = null, ?Closure $echo = null): Process
    {
        $process = new Process(['node', $tools['script'], ...$arguments], (string) getcwd(), array_filter([
            'AGENT_PLAYWRIGHT' => $tools['playwright'],
            'AGENT_CAPTURE_FONTS' => $tools['fonts'],
            'AGENT_BASE_URL' => $url,
            'AGENT_MEDIA_DIR' => $output,
            'AGENT_CAPTURE_COMMAND' => 'rocket capture',
            'NODE_PATH' => $tools['directory'].'/node_modules',
        ], fn (?string $value): bool => $value !== null));
        $process->setTimeout(null);

        return app(CommandLog::class)->run($process, showOutput: $echo === null, callback: $echo === null ? null : fn (string $type, string $buffer) => $echo($buffer));
    }
}
