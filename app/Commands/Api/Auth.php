<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Actions\Credentials;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

class Auth extends Command
{
    use InteractsWithApi;

    protected $signature = 'auth';

    protected $description = 'Configure your Rocketeers API token and default team';

    public function handle(): int
    {
        $credentials = Credentials::make();

        if (blank($credentials->token())) {
            return $this->firstTimeSetup($credentials);
        }

        $user = $this->fetchProfile();

        if ($user === null) {
            $this->newLine();
            $this->warn('Your stored API token could not be verified.');
            $this->line('API URL: '.$credentials->apiUrl().'  (wrong URL? change it below)');

            return $this->menu($credentials, valid: false);
        }

        $this->showAccount($user, $credentials);

        return $this->menu($credentials, valid: true);
    }

    private function firstTimeSetup(Credentials $credentials): int
    {
        $this->info('No API token configured yet.');

        $key = $this->ask('Enter your Rocketeers API token');

        if (blank($key)) {
            $this->error('No token provided.');

            return self::FAILURE;
        }

        $credentials->store('API_TOKEN', $key);

        if (($user = $this->fetchProfile()) === null) {
            $this->warn('Token saved, but it could not be verified. Check the key and your API URL.');

            return self::SUCCESS;
        }

        $this->info('Token saved and verified.');
        $this->showAccount($user, $credentials);

        if ($this->confirm('Set a default team now?', true)) {
            $this->chooseTeam($credentials);
        }

        return self::SUCCESS;
    }

    private function menu(Credentials $credentials, bool $valid): int
    {
        $options = $valid
            ? ['Change API key', 'Set default team', 'Set API URL', 'Logout', 'Cancel']
            : ['Change API key', 'Set API URL', 'Logout', 'Cancel'];

        match ($this->choice('What would you like to do?', $options, count($options) - 1)) {
            'Change API key' => $this->changeKey($credentials),
            'Set default team' => $this->chooseTeam($credentials),
            'Set API URL' => $this->changeUrl($credentials),
            'Logout' => $this->logout($credentials),
            default => $this->line('No changes made.'),
        };

        return self::SUCCESS;
    }

    private function changeKey(Credentials $credentials): void
    {
        $key = $this->ask('Enter your Rocketeers API token');

        if (blank($key)) {
            $this->error('No token provided.');

            return;
        }

        $credentials->store('API_TOKEN', $key);

        if (($user = $this->fetchProfile()) === null) {
            $this->warn('Token saved, but it could not be verified.');

            return;
        }

        $this->info('Token saved and verified.');
        $this->showAccount($user, $credentials);
    }

    private function chooseTeam(Credentials $credentials): void
    {
        try {
            $teams = ApiRequest::make()->paginated('me/teams');
        } catch (StepException $e) {
            $this->error($e->getMessage());

            return;
        }

        if (empty($teams)) {
            $this->warn('You are not a member of any teams.');

            return;
        }

        $slugs = collect($teams)->pluck('slug')->all();
        $current = $credentials->defaultTeam();

        $picked = $this->choice('Select your default team', $slugs, $current && in_array($current, $slugs, true) ? $current : null);

        $credentials->store('DEFAULT_TEAM', $picked);
        $this->info("Default team set to: {$picked}");
    }

    private function changeUrl(Credentials $credentials): void
    {
        $url = $this->ask('Enter the API base URL', $credentials->apiUrl());

        if (blank($url) || (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'))) {
            $this->error('URL must start with http:// or https://');

            return;
        }

        $credentials->store('API_URL', rtrim($url, '/'));
        $this->info('API URL set to: '.rtrim($url, '/'));
    }

    private function logout(Credentials $credentials): void
    {
        if (! $this->confirm('Remove your stored API token?', false)) {
            $this->line('Cancelled.');

            return;
        }

        $credentials->forget('API_TOKEN');
        $this->info('Logged out. Token removed from ~/.rocketeers/.env');
    }

    private function fetchProfile(): ?array
    {
        try {
            return ApiRequest::make()->single('me');
        } catch (StepException) {
            return null;
        }
    }

    private function showAccount(array $user, Credentials $credentials): void
    {
        $name = trim(($user['firstname'] ?? '').' '.($user['lastname'] ?? ''));

        $this->newLine();
        $this->info('Logged in as '.($name ?: 'unknown').' <'.($user['email'] ?? '').'>');
        $this->line('API URL: '.$credentials->apiUrl());
        $this->line('Default team: '.($credentials->defaultTeam() ?: '<none>'));
    }
}
