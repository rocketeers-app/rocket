<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Actions\Credentials;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

class UseTeam extends Command
{
    use InteractsWithApi;

    protected $signature = 'use-team {slug? : Team slug to set as your default}';

    protected $description = 'Set or show your default team';

    public function handle(): int
    {
        $credentials = Credentials::make();

        if ($slug = $this->argument('slug')) {
            $credentials->store('DEFAULT_TEAM', $slug);
            $this->info("Default team set to: {$slug}");

            return self::SUCCESS;
        }

        $current = $credentials->defaultTeam();
        $this->line('Current default team: '.($current ?: '<none>'));

        try {
            $teams = ApiRequest::make()->paginated('me/teams');
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        if (empty($teams)) {
            $this->warn('You are not a member of any teams.');

            return self::SUCCESS;
        }

        $slugs = collect($teams)->pluck('slug')->all();
        $picked = $this->choice('Select your default team', $slugs, $current && in_array($current, $slugs, true) ? $current : null);

        $credentials->store('DEFAULT_TEAM', $picked);
        $this->info("Default team set to: {$picked}");

        return self::SUCCESS;
    }
}
