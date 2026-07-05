<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'rocketeers.api_token' => 'test-token',
        'rocketeers.api_url' => 'https://api.test/v1',
        'rocketeers.default_team' => null,
    ]);
});

function fakeList(): void
{
    Http::fake([
        '*' => Http::response([
            'data' => [],
            'meta' => ['total' => 0, 'per_page' => 50, 'last_page' => 1, 'current_page' => 1],
        ], 200),
    ]);
}

function assertHit(string $needle): void
{
    Http::assertSent(fn (Request $request) => str_contains($request->url(), $needle));
}

it('hits the team route with no scope flags', function () {
    fakeList();

    $this->artisan('daemons', ['--team' => 'acme', '--json' => true])->assertSuccessful();

    assertHit('https://api.test/v1/acme/daemons');
});

it('hits the server route with --server', function () {
    fakeList();

    $this->artisan('daemons', ['--team' => 'acme', '--server' => 'srv-1', '--json' => true])->assertSuccessful();

    assertHit('/acme/servers/srv-1/daemons');
});

it('hits the environment route with --environment', function () {
    fakeList();

    $this->artisan('daemons', ['--team' => 'acme', '--environment' => 'env-1', '--json' => true])->assertSuccessful();

    assertHit('/acme/environments/env-1/daemons');
});

it('treats --env as an alias of --environment', function () {
    fakeList();

    $this->artisan('databases', ['--team' => 'acme', '--env' => 'env-9', '--json' => true])->assertSuccessful();

    assertHit('/acme/environments/env-9/databases');
});

it('hits the single-show route with --id', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'site-1', 'name' => 'Knol']], 200)]);

    $this->artisan('sites', ['--team' => 'acme', '--id' => 'site-1', '--json' => true])->assertSuccessful();

    assertHit('/acme/sites/site-1');
});

it('resolves a custom-render endpoint path', function () {
    Http::fake(['*' => Http::response(['zone' => ['name' => 'example.com'], 'records' => []], 200)]);

    $this->artisan('dns', ['--team' => 'acme', '--domain' => 'example.com', '--json' => true])->assertSuccessful();

    assertHit('/acme/domains/example.com/dns');
});

it('falls back to the configured default team', function () {
    config(['rocketeers.default_team' => 'default-team']);
    fakeList();

    $this->artisan('sites', ['--json' => true])->assertSuccessful();

    assertHit('/default-team/sites');
});

it('lets --team override the default team', function () {
    config(['rocketeers.default_team' => 'default-team']);
    fakeList();

    $this->artisan('sites', ['--team' => 'override', '--json' => true])->assertSuccessful();

    assertHit('/override/sites');
});

it('fails cleanly when no team is available', function () {
    Http::fake();

    $this->artisan('sites', ['--json' => true])->assertFailed();

    Http::assertNothingSent();
});

it('reports unsupported scope combinations', function () {
    Http::fake();

    $this->artisan('deployments', ['--team' => 'acme'])
        ->expectsOutputToContain("doesn't support")
        ->assertFailed();

    Http::assertNothingSent();
});

it('surfaces coverage gaps from the docs diff', function () {
    Http::fake([
        '*/docs' => Http::response([
            'items' => [[
                'operations' => [
                    ['method' => 'GET', 'path' => '/{team}/sites'],
                    ['method' => 'GET', 'path' => '/{team}/unknown-thing'],
                ],
                'subitems' => [],
            ]],
        ], 200),
    ]);

    $this->artisan('check', ['--json' => true])
        ->expectsOutputToContain('unknown-thing')
        ->assertFailed();
});
