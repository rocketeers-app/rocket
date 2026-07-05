<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['rocketeers.api_url' => 'https://rocketeers.app/api/v1']);
});

it('sets the API base URL and refreshes live config', function () {
    $this->artisan('url', ['url' => 'https://rocketeers-app-v2.test/api/v1'])->assertSuccessful();

    expect(config('rocketeers.api_url'))->toBe('https://rocketeers-app-v2.test/api/v1');
    expect(Storage::disk('local')->get('.env'))->toContain('API_URL=https://rocketeers-app-v2.test/api/v1');
});

it('trims a trailing slash from the URL', function () {
    $this->artisan('url', ['url' => 'https://example.test/api/v1/'])->assertSuccessful();

    expect(config('rocketeers.api_url'))->toBe('https://example.test/api/v1');
});

it('rejects a URL without a scheme', function () {
    $this->artisan('url', ['url' => 'rocketeers-app-v2.test/api/v1'])->assertFailed();

    Storage::disk('local')->assertMissing('.env');
});

it('shows the current URL when no argument is given', function () {
    $this->artisan('url')
        ->expectsOutputToContain('https://rocketeers.app/api/v1')
        ->assertSuccessful();
});
