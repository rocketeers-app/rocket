<?php

use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(fn () => actingInTeam());

it('renders a resource collection with its pagination footer', function (): void {
    fakeApi([
        'api.team.environments.index' => MockResponse::make([
            'data' => [environmentRecord(), environmentRecord(['id' => 'e2', 'name' => 'Acme staging', 'slug' => 'acme-staging'])],
            'meta' => ['current_page' => 1, 'last_page' => 3, 'total' => 42, 'per_page' => 20],
        ]),
    ]);

    [$code, $output] = runCommand('environments:list');

    expect($code)->toBe(0)
        ->and($output)->toContain('Acme production', 'Acme staging', 'active', 'Page 1/3 · 42 total · --page=2');
});

it('renders a bare paginator and asks for the page it was given', function (): void {
    $mock = fakeApi([
        'api.team.daemons' => MockResponse::make([
            'current_page' => 2,
            'data' => [['id' => 7, 'name' => 'horizon', 'status' => 'running']],
            'last_page' => 2,
            'total' => 21,
            'meta' => [],
        ]),
    ]);

    [$code, $output] = runCommand('daemons:list', ['--page' => 2, '--search' => 'hor']);

    expect($code)->toBe(0)->and($output)->toContain('horizon', 'Page 2/2 · 21 total')->not->toContain('--page=3');

    $mock->assertSent(fn ($request, $response) => $response->getPendingRequest()->query()->get('page') == 2
        && $response->getPendingRequest()->query()->get('search') === 'hor');
});

it('turns a slug into the id the route binds on', function (): void {
    $record = environmentRecord();

    $mock = fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [$record], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.environments.show' => fn (PendingRequest $pending) => MockResponse::make(['data' => $record + ['php_version' => '8.4']]),
    ]);

    [$code, $output] = runCommand('environments:read', ['environment' => 'acme-production']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Acme production', 'Php Version', '8.4', 'Commands for this environment', 'environments:deploy');

    $mock->assertSent(fn ($request, $response) => str_ends_with($response->getPendingRequest()->getUrl(), '/acme/environments/'.$record['id']));
});

it('suggests close matches when a slug matches nothing', function (): void {
    fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [environmentRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
    ]);

    [$code, $output] = runCommand('environments:read', ['environment' => 'acme', '--no-interaction' => true]);

    expect($code)->toBe(1)->and($output)->toContain('No environment `acme` found', 'Did you mean: Acme production');
});

it('asks for the record when a slug matches nothing and someone can answer', function (): void {
    $record = environmentRecord();

    $mock = fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [$record], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.environments.show' => MockResponse::make(['data' => $record]),
    ]);

    $this->artisan('environments:read', ['environment' => 'acme-prod'])
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', 'id:'.$record['id'], ['id:'.$record['id'] => 'Acme production  (acme-production)'])
        ->expectsOutputToContain('Acme production')
        ->assertSuccessful();

    $mock->assertSent(fn ($request, $response) => str_ends_with($response->getPendingRequest()->getUrl(), '/acme/environments/'.$record['id']));
});
