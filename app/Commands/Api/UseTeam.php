<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Actions\Credentials;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

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
            info("Default team set to: {$slug}");

            return self::SUCCESS;
        }

        try {
            $teams = ApiRequest::make()->paginated('me/teams');
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        if (empty($teams)) {
            warning('You are not a member of any teams.');

            return self::SUCCESS;
        }

        $options = collect($teams)->pluck('name', 'slug')->all();
        $current = $credentials->defaultTeam();

        $picked = select(
            label: 'Select your default team',
            options: $options,
            default: $current && isset($options[$current]) ? $current : null,
            hint: $current ? "Current: {$current}" : '',
        );

        $credentials->store('DEFAULT_TEAM', $picked);
        info("Default team set to: {$picked}");

        return self::SUCCESS;
    }
}
