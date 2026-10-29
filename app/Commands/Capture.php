<?php

namespace App\Commands;

use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\FindLocalSiteUrl;
use App\Actions\PrepareCapture;
use App\Actions\PublishCapture;
use App\Actions\RunCapture;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Commands\Concerns\WithSteps;
use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Support\CommandLog;
use App\Support\PermissionGate;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\text;

/**
 * Records a storyboard against the local site with the agent-capture script the agent runner image carries, so a
 * capture made here looks like one made in an agent session: screenshots per scene and a walkthrough video, in an
 * output directory of its own (the script empties it first). With --publish the result goes to the changelog of an
 * environment through the API, as the capture of an agent session does.
 */
class Capture extends Command
{
    use OutputsJson;
    use ResolvesTeam;
    use WithSteps;

    protected $signature = 'capture
        {storyboard? : The storyboard JSON file; asked for when left out}
        {--screenshots-only : Take the screenshots alone, without pauses or a video}
        {--example : Print an example storyboard}
        {--url= : The URL of the site to record; defaults to the Herd or Valet site of the current directory}
        {--output= : The directory the screenshots, video and manifest.json go to; it is emptied first}
        {--publish= : Publish the capture to the changelog of this environment (slug); asked for when left empty}
        {--team= : Only look in this team for the environment to publish to}';

    protected $description = 'Record screenshots and a walkthrough video of the local site from a storyboard';

    public function handle(): int
    {
        if ($this->option('example')) {
            return $this->example();
        }

        $storyboard = $this->storyboard();
        $video = ! $this->option('screenshots-only');
        $target = $this->publishing() ? $this->environmentToPublishTo() : null;
        $output = $this->outputDirectory();

        $tools = $this->step('Preparing agent-capture', fn (): array => app(PrepareCapture::class)->handle($video));
        $url = $this->option('url') ?: $this->step('Finding the local site', fn (): string => app(FindLocalSiteUrl::class)->handle((string) getcwd()));

        if (! $this->wantsJson()) {
            $this->components->info("Recording {$url} into {$output}");
        }

        $process = app(RunCapture::class)->handle(
            $tools,
            [...($video ? [] : ['--screenshots-only']), $storyboard],
            rtrim((string) $url, '/'),
            $output,
            $this->wantsJson() ? null : fn (string $buffer) => $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW),
        );

        if (! $process->isSuccessful()) {
            throw new StepException('Nothing was captured'.($this->wantsJson() ? ': '.Str::limit(trim($process->getOutput().' '.$process->getErrorOutput()), 1000) : '; see the output above.'));
        }

        $entry = $target === null ? null : $this->step(
            'Publishing to the changelog of '.$target['environment']['slug'],
            fn (): array => app(PublishCapture::class)->handle($target['team'], $target['environment'], $output, $this->currentBranch()),
        );

        if ($this->wantsJson()) {
            return $this->emitJson(array_filter([
                'url' => $url,
                'output' => $output,
                'manifest' => json_decode((string) @file_get_contents("{$output}/manifest.json"), true),
                'changelog_entry' => $entry,
            ], fn (mixed $value): bool => $value !== null));
        }

        if ($entry !== null) {
            $this->components->info("Published \"{$entry['title']}\" to the changelog of {$target['environment']['slug']} with {$entry['screenshots_count']} ".Str::plural('screenshot', (int) $entry['screenshots_count']).'.');
        }

        return self::SUCCESS;
    }

    private function example(): int
    {
        $tools = app(PrepareCapture::class)->handle(video: false, install: false);
        $process = app(RunCapture::class)->handle($tools, ['--example']);

        if (! $process->isSuccessful()) {
            throw new StepException('The example storyboard could not be printed: '.trim($process->getErrorOutput()));
        }

        $this->output->write($process->getOutput(), false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function storyboard(): string
    {
        $file = $this->argument('storyboard');

        if (blank($file)) {
            if (! $this->canPrompt()) {
                throw new ApiException('Missing the storyboard.', 422, errors: ['storyboard' => ['Pass the storyboard file: rocket capture <storyboard.json>. `rocket capture --example` prints one to start from.']]);
            }

            $file = text(label: 'Storyboard', placeholder: 'storyboard.json', required: true, validate: fn (string $value): ?string => is_file($value) ? null : "There is no file at {$value}.");
        }

        if (! is_file((string) $file)) {
            throw new StepException("There is no storyboard at {$file}. `rocket capture --example` prints one to start from.");
        }

        return (string) realpath((string) $file);
    }

    private function publishing(): bool
    {
        return $this->input->hasParameterOption('--publish', true) || filled($this->option('publish'));
    }

    private function environmentToPublishTo(): array
    {
        $this->ensureToken();

        $target = (new FindEnvironmentAcrossTeams)($this->option('publish') ?: null, $this->canPrompt(), $this->teamFilter());
        $store = app(SchemaCache::class)->findOrRefresh('api.team.environments.changelog.store')
            ?? throw new StepException('This version of the API cannot write changelog entries. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($store, $target['team']);

        return $target;
    }

    private function outputDirectory(): string
    {
        $default = rtrim((string) config('rocketeers.capture.path'), '/').'/media/'.Str::slug(basename((string) getcwd()) ?: 'site');
        $output = rtrim((string) ($this->option('output') ?: $default), '/');
        $output = str_starts_with($output, '~/') ? rtrim((string) getenv('HOME'), '/').substr($output, 1) : $output;

        if (! str_starts_with($output, '/')) {
            $output = getcwd().'/'.$output;
        }

        $isUnknownDirectory = is_dir($output) && ! is_file("{$output}/manifest.json") && array_diff((array) scandir($output), ['.', '..']) !== [];

        if ($isUnknownDirectory) {
            throw new StepException("{$output} already holds files that are not a capture. agent-capture empties its output directory first, so pick an empty or new one with --output.");
        }

        return $output;
    }

    private function currentBranch(): ?string
    {
        $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], (string) getcwd());
        app(CommandLog::class)->run($process);
        $branch = trim($process->getOutput());

        return $process->isSuccessful() && $branch !== '' && $branch !== 'HEAD' ? $branch : null;
    }
}
