<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Actions\Credentials;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

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
            warning('Your stored API token could not be verified.');
            note('API URL: '.$credentials->apiUrl().'  (wrong URL? change it below)');

            return $this->menu($credentials, valid: false);
        }

        $this->showAccount($user, $credentials);

        return $this->menu($credentials, valid: true);
    }

    private function firstTimeSetup(Credentials $credentials): int
    {
        info('No API token configured yet.');

        $credentials->store('API_TOKEN', text('Enter your Rocketeers API token', required: true));

        if (($user = $this->fetchProfile()) === null) {
            warning('Token saved, but it could not be verified. Check the key and your API URL ('.$credentials->apiUrl().').');

            return self::SUCCESS;
        }

        info('Token saved and verified.');
        $this->showAccount($user, $credentials);

        if (confirm('Set a default team now?', default: true)) {
            $this->chooseTeam($credentials);
        }

        return self::SUCCESS;
    }

    private function menu(Credentials $credentials, bool $valid): int
    {
        $options = array_filter([
            'change' => 'Change API key',
            'team' => $valid ? 'Set default team' : null,
            'url' => 'Set API URL',
            'logout' => 'Log out',
            'cancel' => 'Cancel',
        ]);

        match (select('What would you like to do?', $options, default: 'cancel')) {
            'change' => $this->changeKey($credentials),
            'team' => $this->chooseTeam($credentials),
            'url' => $this->changeUrl($credentials),
            'logout' => $this->logout($credentials),
            default => note('No changes made.'),
        };

        return self::SUCCESS;
    }

    private function changeKey(Credentials $credentials): void
    {
        $credentials->store('API_TOKEN', text('Enter your Rocketeers API token', required: true));

        if (($user = $this->fetchProfile()) === null) {
            warning('Token saved, but it could not be verified.');

            return;
        }

        info('Token saved and verified.');
        $this->showAccount($user, $credentials);
    }

    private function chooseTeam(Credentials $credentials): void
    {
        try {
            $teams = ApiRequest::make()->paginated('me/teams');
        } catch (StepException $e) {
            warning($e->getMessage());

            return;
        }

        if (empty($teams)) {
            warning('You are not a member of any teams.');

            return;
        }

        $options = collect($teams)->pluck('name', 'slug')->all();
        $current = $credentials->defaultTeam();

        $picked = select(
            label: 'Select your default team',
            options: $options,
            default: $current && isset($options[$current]) ? $current : null,
        );

        $credentials->store('DEFAULT_TEAM', $picked);
        info("Default team set to: {$picked}");
    }

    private function changeUrl(Credentials $credentials): void
    {
        $url = text(
            label: 'Enter the API base URL',
            default: $credentials->apiUrl() ?? '',
            required: true,
            validate: fn (string $value) => str_starts_with($value, 'http://') || str_starts_with($value, 'https://')
                ? null
                : 'URL must start with http:// or https://',
        );

        $credentials->store('API_URL', rtrim($url, '/'));
        info('API URL set to: '.rtrim($url, '/'));
    }

    private function logout(Credentials $credentials): void
    {
        if (! confirm('Remove your stored API token?', default: false)) {
            note('Cancelled.');

            return;
        }

        $credentials->forget('API_TOKEN');
        info('Logged out. Token removed from ~/.rocketeers/.env');
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

        note(implode(PHP_EOL, [
            'Logged in as '.($name ?: 'unknown').' <'.($user['email'] ?? '').'>',
            'API URL:      '.$credentials->apiUrl(),
            'Default team: '.($credentials->defaultTeam() ?: '—'),
        ]));
    }
}
