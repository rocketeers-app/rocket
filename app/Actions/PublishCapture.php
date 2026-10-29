<?php

namespace App\Actions;

use App\Api\Requests\CallOperation;
use App\Exceptions\StepException;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Publishes what agent-capture recorded to the changelog of an environment, as the platform does with the capture
 * of an agent session: the entry with its walkthrough video in one request, then every screenshot with its title,
 * caption, page and moment in the video, in storyboard order. Only file names agent-capture writes are read from
 * the manifest, so a hand-edited one cannot send other files from this machine.
 */
class PublishCapture
{
    use AsAction;

    public const string SCREENSHOT_PATTERN = '#^screenshots/[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.png$#';

    public const string VIDEO_PATTERN = '#^video\.(mp4|webm)$#';

    public function handle(array $team, array $environment, string $output, ?string $branch = null): array
    {
        $manifest = $this->manifest($output);
        $store = $this->operation('api.team.environments.changelog.store');
        $scenes = collect((array) ($manifest['scenes'] ?? []))
            ->filter(fn (mixed $scene): bool => is_array($scene) && $this->isMedia($output, $scene['screenshot'] ?? null, self::SCREENSHOT_PATTERN))
            ->values();
        $screenshots = $scenes->isEmpty() ? null : $this->operation('api.team.environments.changelog.screenshots.store');

        app(PermissionGate::class)->ensure($store, $team);

        if ($screenshots !== null) {
            app(PermissionGate::class)->ensure($screenshots, $team);
        }

        $video = $this->isMedia($output, $manifest['video'] ?? null, self::VIDEO_PATTERN) ? (string) $manifest['video'] : null;
        $path = ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']];

        $entry = $this->send($store, $path, [
            'title' => $this->text($manifest['title'] ?? null, 250) ?? 'Capture',
            'intro' => $this->text($manifest['intro'] ?? null, 2000),
            'summary' => $this->text($manifest['summary'] ?? null, 10000),
            'branch' => $branch,
            'video' => $video === null ? null : "{$output}/{$video}",
            'video_duration' => $video === null ? null : $this->seconds($manifest['duration'] ?? null),
            'video_summary_offset' => $video === null ? null : $this->seconds($manifest['summary_at'] ?? null),
        ], $team);

        foreach ($scenes as $index => $scene) {
            $this->send($screenshots, [...$path, 'changelogEntry' => (string) $entry['id']], [
                'screenshot' => "{$output}/{$scene['screenshot']}",
                'title' => $this->text($scene['title'] ?? null, 250),
                'caption' => $this->text($scene['caption'] ?? null, 2000),
                'url' => $this->text($scene['url'] ?? null, 250),
                'video_offset' => $video === null ? null : $this->seconds($scene['at'] ?? null),
                'position' => $index + 1,
            ], $team);
        }

        return [...$entry, 'screenshots_count' => $scenes->count()];
    }

    private function manifest(string $output): array
    {
        $contents = is_file("{$output}/manifest.json") ? file_get_contents("{$output}/manifest.json") : false;
        $manifest = $contents === false ? null : json_decode($contents, true);

        if (! is_array($manifest)) {
            throw new StepException("There is no capture to publish in {$output}: its manifest.json is missing or not valid JSON.");
        }

        return $manifest;
    }

    private function operation(string $route): Operation
    {
        return app(SchemaCache::class)->findOrRefresh($route)
            ?? throw new StepException('This version of the API cannot write changelog entries. Run `rocket api:refresh`.');
    }

    private function send(Operation $operation, array $path, array $body, array $team): array
    {
        $response = (new SendApiRequest)(
            CallOperation::for($operation, $path, [], array_filter($body, fn (mixed $value): bool => $value !== null)),
            teamName: (string) $team['name'],
        );

        $data = $response->json('data') ?? $response->json();

        return is_array($data) ? $data : [];
    }

    private function isMedia(string $output, mixed $file, string $pattern): bool
    {
        return is_string($file) && preg_match($pattern, $file) === 1 && is_file("{$output}/{$file}");
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function seconds(mixed $value): ?float
    {
        return is_numeric($value) && $value >= 0 ? round((float) $value, 1) : null;
    }
}
