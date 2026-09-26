<?php

use App\Api\Requests\CallOperation;
use App\Support\Teams;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\TestCase;

putenv('HOME='.TestCase::HOME);
$_SERVER['HOME'] = $_ENV['HOME'] = TestCase::HOME;

uses(TestCase::class)->in('Feature', 'Unit');

/** @param array<string, MockResponse|Closure> $responses */
function fakeApi(array $responses): MockClient
{
    return MockClient::global([
        '*' => function (PendingRequest $pending) use ($responses): MockResponse {
            $request = $pending->getRequest();
            $key = $request instanceof CallOperation ? $request->operation->name : $request::class;
            $response = $responses[$key] ?? MockResponse::make(['message' => "Not faked: {$key}"], 500);

            return $response instanceof Closure ? $response($pending) : $response;
        },
    ]);
}

/** @param array<int, string>|null $permissions */
function actingInTeam(?array $permissions = null, string $slug = 'acme', string $name = 'Acme'): void
{
    Storage::put(Teams::PATH, (string) json_encode([
        'token' => hash('sha256', 'test-token'),
        'fetched_at' => time(),
        'teams' => [array_filter(['id' => 'team-1', 'name' => $name, 'slug' => $slug, 'permissions' => $permissions], fn ($value) => $value !== null)],
    ]));
}

/** @return array{0: int, 1: string} */
function runCommand(string $command, array $parameters = []): array
{
    $code = Artisan::call($command, $parameters);

    return [$code, Artisan::output()];
}

/** @return array<string, mixed> */
function environmentRecord(array $overrides = []): array
{
    return [
        'id' => '9f1c2e4a-0000-4000-8000-000000000001',
        'name' => 'Acme production',
        'slug' => 'acme-production',
        'status' => 'active',
        'type' => 'laravel',
        'created_at' => '2026-09-01T10:00:00+00:00',
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function serverRecord(array $overrides = []): array
{
    return [
        'id' => '9f1c2e4a-0000-4000-8000-0000000000aa',
        'name' => 'web-1',
        'slug' => 'web-1',
        'ip' => '10.0.0.1',
        'status' => 'ready',
        ...$overrides,
    ];
}
