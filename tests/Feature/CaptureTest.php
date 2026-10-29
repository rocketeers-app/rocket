<?php

use App\Actions\FindLocalSiteUrl;
use App\Actions\PrepareCapture;
use App\Actions\RunCapture;
use App\Api\Requests\CallOperationWithFiles;
use Illuminate\Support\Facades\File;
use Saloon\Data\MultipartValue;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

beforeEach(function (): void {
    actingInTeam();

    config(['rocketeers.capture.path' => TestCase::HOME.'/.rocketeers/capture']);

    $this->storyboard = TestCase::HOME.'/storyboard.json';
    File::put($this->storyboard, json_encode(['title' => 'Export invoices', 'scenes' => [['title' => 'Export', 'goto' => '/invoices']]]));
    $this->output = TestCase::HOME.'/capture';

    $this->mock(PrepareCapture::class)->shouldReceive('handle')->andReturn([
        'directory' => TestCase::HOME.'/.rocketeers/capture',
        'script' => TestCase::HOME.'/.rocketeers/capture/agent-capture.cjs',
        'fonts' => TestCase::HOME.'/.rocketeers/capture/fonts.css',
        'playwright' => TestCase::HOME.'/.rocketeers/capture/node_modules/playwright',
    ]);
});

function captureProcess(bool $successful = true): Process
{
    return tap(new Process([PHP_BINARY, '-r', $successful ? 'exit(0);' : 'exit(1);']))->run();
}

function fakeCaptureRun(string $output, bool $video = true): void
{
    test()->mock(RunCapture::class)->shouldReceive('handle')->once()
        ->withArgs(function (array $tools, array $arguments, ?string $url, ?string $directory) use ($output, $video): bool {
            File::ensureDirectoryExists("{$output}/screenshots");
            File::put("{$output}/screenshots/01-export.png", "\x89PNG\r\n\x1A\n");
            File::put("{$output}/screenshots/02-filter.png", "\x89PNG\r\n\x1A\n");

            if ($video) {
                File::put("{$output}/video.mp4", "\0\0\0\x18ftypmp42");
            }

            File::put("{$output}/manifest.json", json_encode([
                'version' => 1,
                'title' => 'Export invoices',
                'intro' => 'An Export button.',
                'summary' => 'Invoices export as CSV.',
                'video' => $video ? 'video.mp4' : null,
                'duration' => $video ? 21.4 : null,
                'summary_at' => $video ? 18.2 : null,
                'scenes' => [
                    ['title' => 'Export', 'caption' => 'The button.', 'url' => '/invoices', 'screenshot' => 'screenshots/01-export.png', 'at' => 3.1, 'error' => null],
                    ['title' => 'Broken', 'caption' => null, 'url' => null, 'screenshot' => null, 'at' => null, 'error' => 'timed out'],
                    ['title' => 'Filter', 'caption' => 'Only Acme.', 'url' => '/invoices?search=Acme', 'screenshot' => 'screenshots/02-filter.png', 'at' => 9.5, 'error' => null],
                    ['title' => 'Sneaky', 'screenshot' => '../../.ssh/id_rsa'],
                ],
            ]));

            return $directory === $output && end($arguments) === test()->storyboard && in_array('--screenshots-only', $arguments, true) === ! $video;
        })
        ->andReturn(captureProcess());
}

function multipartFields(CallOperationWithFiles $request): array
{
    return collect($request->body()->all())
        ->map(fn (MultipartValue $value): array => [$value->name, $value->filename ?? $value->value])
        ->all();
}

it('records the storyboard against the Herd site of the current directory', function (): void {
    $this->mock(FindLocalSiteUrl::class)->shouldReceive('handle')->once()->andReturn('https://rocket.test');
    fakeCaptureRun($this->output);

    [$code, $output] = runCommand('capture', ['storyboard' => $this->storyboard, '--output' => $this->output, '--json' => true]);

    $result = json_decode($output, true);

    expect($code)->toBe(0)
        ->and($result['url'])->toBe('https://rocket.test')
        ->and($result['output'])->toBe($this->output)
        ->and($result['manifest']['video'])->toBe('video.mp4')
        ->and($result)->not->toHaveKey('changelog_entry');
});

it('takes the url from --url and screenshots alone with --screenshots-only', function (): void {
    $this->mock(FindLocalSiteUrl::class)->shouldNotReceive('handle');
    fakeCaptureRun($this->output, video: false);

    [$code] = runCommand('capture', ['storyboard' => $this->storyboard, '--output' => $this->output, '--url' => 'http://localhost:8000/', '--screenshots-only' => true, '--json' => true]);

    expect($code)->toBe(0);
});

it('refuses to empty a directory that holds other files', function (): void {
    File::ensureDirectoryExists($this->output);
    File::put("{$this->output}/important.txt", 'keep me');
    $this->mock(RunCapture::class)->shouldNotReceive('handle');

    [$code, $output] = runCommand('capture', ['storyboard' => $this->storyboard, '--output' => $this->output, '--url' => 'https://rocket.test', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true)['error']['message'])->toContain('already holds files that are not a capture')
        ->and(File::exists("{$this->output}/important.txt"))->toBeTrue();
});

it('names the storyboard a script still has to pass', function (): void {
    [$code, $output] = runCommand('capture', ['--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error']['errors'])->toHaveKey('storyboard');
});

it('publishes the capture to the changelog of an environment, the video with the entry and every screenshot after it', function (): void {
    fakeCaptureRun($this->output);

    $mock = fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [environmentRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.environments.changelog.store' => MockResponse::make(['data' => ['id' => 'entry-1', 'title' => 'Export invoices']], 201),
        'api.team.environments.changelog.screenshots.store' => MockResponse::make(['data' => ['id' => 'shot']], 201),
    ]);

    [$code, $output] = runCommand('capture', ['storyboard' => $this->storyboard, '--output' => $this->output, '--url' => 'https://rocket.test', '--publish' => 'acme-production', '--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true)['changelog_entry'])->toMatchArray(['id' => 'entry-1', 'screenshots_count' => 2]);

    $mock->assertSent(function ($request) {
        if (! $request instanceof CallOperationWithFiles || $request->operation->name !== 'api.team.environments.changelog.store') {
            return false;
        }

        $fields = multipartFields($request);

        return $request->resolveEndpoint() === '/acme/environments/'.environmentRecord()['id'].'/changelog'
            && in_array(['title', 'Export invoices'], $fields, true)
            && in_array(['video', 'video.mp4'], $fields, true)
            && in_array(['video_duration', '21.4'], $fields, true)
            && in_array(['video_summary_offset', '18.2'], $fields, true);
    });

    $mock->assertSentCount(4);

    $mock->assertSent(fn ($request) => $request instanceof CallOperationWithFiles
        && $request->operation->name === 'api.team.environments.changelog.screenshots.store'
        && str_ends_with($request->resolveEndpoint(), '/changelog/entry-1/screenshots')
        && $request->payload['title'] === 'Filter'
        && $request->payload['position'] === 2
        && $request->payload['video_offset'] === 9.5
        && in_array(['screenshot', '02-filter.png'], multipartFields($request), true));
});

it('publishes nothing when the capture failed', function (): void {
    $this->mock(RunCapture::class)->shouldReceive('handle')->once()->andReturn(captureProcess(successful: false));

    $mock = fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [environmentRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
    ]);

    [$code] = runCommand('capture', ['storyboard' => $this->storyboard, '--output' => $this->output, '--url' => 'https://rocket.test', '--publish' => 'acme-production', '--json' => true]);

    expect($code)->toBe(1);

    $mock->assertSentCount(1);
});

it('refuses to publish for a team that may not write the changelog, before recording anything', function (): void {
    actingInTeam(['environments:read', 'changelog:read']);
    $this->mock(RunCapture::class)->shouldNotReceive('handle');

    $mock = fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [environmentRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
    ]);

    [$code, $output] = runCommand('capture', ['storyboard' => $this->storyboard, '--output' => $this->output, '--url' => 'https://rocket.test', '--publish' => 'acme-production', '--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error']['permission'])->toBe('changelog:create');

    $mock->assertSentCount(1);
});
