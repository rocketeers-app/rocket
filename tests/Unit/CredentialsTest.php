<?php

use App\Actions\Credentials;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['rocketeers.api_token' => null, 'rocketeers.default_team' => null]);
});

it('stores a key and refreshes live config in the same process', function () {
    Credentials::make()->store('DEFAULT_TEAM', 'team-x');

    expect(config('rocketeers.default_team'))->toBe('team-x');
    Storage::disk('local')->assertExists('.env');
    expect(Storage::disk('local')->get('.env'))->toContain('DEFAULT_TEAM=team-x');
});

it('forgets a key and nulls live config', function () {
    $credentials = Credentials::make();
    $credentials->store('API_TOKEN', 'abc123');

    expect(config('rocketeers.api_token'))->toBe('abc123');

    $credentials->forget('API_TOKEN');

    expect(config('rocketeers.api_token'))->toBeNull();
    expect(Storage::disk('local')->get('.env'))->not->toContain('API_TOKEN');
});

it('preserves other keys when writing', function () {
    $credentials = Credentials::make();
    $credentials->store('API_TOKEN', 'tok');
    $credentials->store('DEFAULT_TEAM', 'team-y');

    $contents = Storage::disk('local')->get('.env');

    expect($contents)->toContain('API_TOKEN=tok')
        ->and($contents)->toContain('DEFAULT_TEAM=team-y');
});
