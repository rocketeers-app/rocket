<?php

use App\Actions\ApiRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'rocketeers.api_token' => 'test-token',
        'rocketeers.api_url' => 'https://api.test/v1',
        'rocketeers.default_team' => null,
    ]);
    Http::fake(['*' => Http::response(['data' => ['id' => 'x']], 200)]);
});

it('ApiRequest::post sends a JSON body', function () {
    ApiRequest::make()->post('acme/clients', ['name' => 'Acme']);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_contains($r->url(), 'https://api.test/v1/acme/clients')
        && $r['name'] === 'Acme'
        && $r->hasHeader('Authorization', 'Bearer test-token'));
});

it('post command sends -F fields as body', function () {
    $this->artisan('post', ['path' => 'clients', '--team' => 'acme', '--field' => ['name=Raw'], '--json' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'Raw');
});

it('put command targets the given path', function () {
    $this->artisan('put', ['path' => 'clients/c1', '--team' => 'acme', '--field' => ['name=New'], '--json' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/acme/clients/c1'));
});

it('patch command sends a PATCH', function () {
    $this->artisan('patch', ['path' => 'incidents/i1/acknowledge', '--team' => 'acme', '--json' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/acme/incidents/i1/acknowledge'));
});

it('delete command with --force sends DELETE', function () {
    $this->artisan('delete', ['path' => 'clients/c1', '--team' => 'acme', '--force' => true, '--json' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/acme/clients/c1'));
});

it('delete command without --force aborts and sends nothing', function () {
    $this->artisan('delete', ['path' => 'clients/c1', '--team' => 'acme'])->assertSuccessful();

    Http::assertNothingSent();
});

it('merges --data JSON with --field and casts values', function () {
    $this->artisan('post', [
        'path' => 'domains/d1/dns/records',
        '--team' => 'acme',
        '--data' => '{"type":"A","name":"root"}',
        '--field' => ['ttl=3600', 'proxied=true'],
        '--json' => true,
    ])->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r['type'] === 'A' && $r['name'] === 'root' && $r['ttl'] === 3600 && $r['proxied'] === true);
});

it('resolves an absolute path without prefixing the team', function () {
    $this->artisan('post', ['path' => '/ping', '--team' => 'acme', '--json' => true])->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'https://api.test/v1/ping'));
});
