<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'rocketeers.api_token' => 'test-token',
        'rocketeers.api_url' => 'https://api.test/v1',
        'rocketeers.default_team' => null,
    ]);
    Http::fake([
        '*/me/teams*' => Http::response(['data' => [['slug' => 'acme', 'id' => 'team-123']], 'meta' => ['last_page' => 1]], 200),
        '*' => Http::response(['data' => ['id' => 'new', 'name' => 'Created']], 201),
    ]);
});

it('creates a record via --create with -F fields', function () {
    $this->artisan('clients', ['--create' => true, '--team' => 'acme', '--field' => ['name=Acme'], '--json' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_contains($r->url(), '/acme/clients')
        && $r['name'] === 'Acme');
});

it('updates a record via --update --id', function () {
    $this->artisan('clients', ['--update' => true, '--id' => 'c1', '--team' => 'acme', '--field' => ['name=New'], '--json' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/acme/clients/c1'));
});

it('deletes a record via --delete --id --force', function () {
    $this->artisan('clients', ['--delete' => true, '--id' => 'c1', '--team' => 'acme', '--force' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/acme/clients/c1'));
});

it('aborts a destructive delete without --force', function () {
    $this->artisan('clients', ['--delete' => true, '--id' => 'c1', '--team' => 'acme'])->assertSuccessful();

    Http::assertNothingSent();
});

it('runs a named --action', function () {
    $this->artisan('servers', ['--action' => 'reboot', '--id' => 's1', '--team' => 'acme', '--force' => true, '--json' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/acme/servers/s1/reboot'));
});

it('resolves a nested create scope from --server', function () {
    $this->artisan('daemons', [
        '--create' => true, '--server' => 's1', '--team' => 'acme',
        '--field' => ['name=worker', 'command=php artisan queue:work'], '--json' => true,
    ])->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/acme/servers/s1/daemons'));
});

it('injects team_id from context on create', function () {
    $this->artisan('sites', [
        '--create' => true, '--team' => 'acme',
        '--field' => ['name=Site', 'manager_id=m1'], '--json' => true,
    ])->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_contains($r->url(), '/acme/sites')
        && ($r['team_id'] ?? null) === 'team-123');
});

it('errors on an unknown action', function () {
    $this->artisan('clients', ['--action' => 'frobnicate', '--team' => 'acme'])
        ->expectsOutputToContain('no write action')
        ->assertFailed();

    Http::assertNothingSent();
});

it('errors on an unsupported write scope', function () {
    $this->artisan('sites', ['--update' => true, '--team' => 'acme'])
        ->expectsOutputToContain('combination of scopes')
        ->assertFailed();

    Http::assertNothingSent();
});

it('errors when a required field is missing non-interactively', function () {
    $this->artisan('clients', ['--create' => true, '--team' => 'acme'])
        ->expectsOutputToContain('Missing required field')
        ->assertFailed();

    Http::assertNothingSent();
});
