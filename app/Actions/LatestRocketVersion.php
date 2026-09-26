<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/** The newest stable Rocket version, read straight from the tags of rocketeers-app/rocket on GitHub (Packagist lags behind a release). */
class LatestRocketVersion
{
    use AsAction;

    public const string REPOSITORY = 'rocketeers-app/rocket';

    public function handle(): string
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent('rocket-cli/'.config('app.version'))
                ->timeout(15)
                ->get('https://api.github.com/repos/'.self::REPOSITORY.'/git/matching-refs/tags/v');
        } catch (ConnectionException) {
            throw new StepException('Could not reach GitHub to check for a new version.');
        }

        if (! $response->successful()) {
            throw new StepException("Could not check for a new version: GitHub answered HTTP {$response->status()}.");
        }

        return collect($response->json())
            ->map(fn (mixed $ref): string => Str::after((string) ($ref['ref'] ?? ''), 'refs/tags/v'))
            ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+\.\d+$/', $version) === 1)
            ->sort(fn (string $a, string $b): int => version_compare($a, $b))
            ->last() ?? throw new StepException('Could not find a released version of Rocket on GitHub.');
    }
}
