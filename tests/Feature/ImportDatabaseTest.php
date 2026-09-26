<?php

use App\Actions\ImportServerDatabase;
use App\Support\Teams;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    Storage::put(Teams::PATH, (string) json_encode([
        'token' => hash('sha256', 'test-token'),
        'fetched_at' => time(),
        'teams' => [
            ['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme'],
            ['id' => 'team-2', 'name' => 'Globex', 'slug' => 'globex'],
        ],
    ]));
});

function importDatabaseRecord(string $name, string $driver, ?array $server): array
{
    return ['id' => 'db-'.$name, 'name' => $name, 'driver' => $driver, 'type' => str_starts_with($driver, 'pgsql') ? 'postgresql' : 'mysql', 'server' => $server];
}

function fakeImportApi(array $databases): void
{
    fakeApi([
        'api.team.environments.index' => fn (PendingRequest $pending) => str_contains($pending->getUrl(), '/globex/')
            ? paginatedRecords([environmentRecord(['slug' => 'shop-production', 'name' => 'Shop production'])])
            : paginatedRecords([]),
        'api.team.environments.databases.index' => fn (PendingRequest $pending) => str_contains($pending->getUrl(), '/globex/environments/'.environmentRecord()['id'].'/databases')
            ? paginatedRecords($databases)
            : MockResponse::make(['message' => 'Wrong team'], 404),
        'api.team.environments.servers.index' => paginatedRecords([serverRecord(['ip' => '10.0.0.1'])]),
    ]);
}

function paginatedRecords(array $records): MockResponse
{
    return MockResponse::make(['data' => $records, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($records)]]);
}

it('finds the environment in whichever team has it and imports its one database from the server it lives on', function (): void {
    fakeImportApi([
        importDatabaseRecord('shop', 'mysql_native', ['name' => 'db-1', 'ip' => '10.0.0.9']),
        importDatabaseRecord('events', 'tinybird', null),
        importDatabaseRecord('analytics', 'clickhouse_native', ['name' => 'db-1', 'ip' => '10.0.0.9']),
    ]);

    $this->mock(ImportServerDatabase::class)->shouldReceive('handle')
        ->once()
        ->withArgs(fn (array $database, string $environment, array $hosts, string $local) => $database['name'] === 'shop'
            && $environment === 'shop-production' && $hosts === ['10.0.0.1'] && $local === 'shop');

    [$code, $output] = runCommand('db:import', ['environment' => 'shop-production']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Skipping events (Tinybird), analytics (ClickHouse)', 'shop → local shop', 'MySQL from 10.0.0.9');
});

it('imports every MySQL and PostgreSQL database with --all, each from its own server', function (): void {
    fakeImportApi([
        importDatabaseRecord('shop', 'mysql_native', ['name' => 'db-1', 'ip' => '10.0.0.9']),
        importDatabaseRecord('ledger', 'pgsql_native', ['name' => 'db-2', 'ip' => '10.0.0.10']),
        importDatabaseRecord('cache', 'planetscale', null),
    ]);

    $imported = [];
    $this->mock(ImportServerDatabase::class)->shouldReceive('handle')->twice()->andReturnUsing(function (array $database) use (&$imported): void {
        $imported[] = $database['name'].'@'.$database['server']['ip'];
    });

    [$code, $output] = runCommand('db:import', ['environment' => 'shop-production', '--all' => true, '--json' => true]);

    expect($code)->toBe(0)
        ->and($imported)->toBe(['shop@10.0.0.9', 'ledger@10.0.0.10'])
        ->and(json_decode($output, true))->toMatchArray([
            'team' => 'globex',
            'environment' => 'shop-production',
            'skipped' => [['name' => 'cache', 'type' => 'PlanetScale']],
        ]);
});

it('matches the environment on its slug only, never on a name that merely looks alike', function (): void {
    $mock = fakeApi([
        'api.team.environments.index' => fn (PendingRequest $pending) => str_contains($pending->getUrl(), '/globex/')
            ? paginatedRecords([environmentRecord(['slug' => 'habits-production', 'name' => 'Habits']), environmentRecord(['id' => 'e2', 'slug' => 'habits', 'name' => 'habits-production'])])
            : paginatedRecords([]),
        'api.team.environments.databases.index' => paginatedRecords([importDatabaseRecord('habits', 'mysql_native', ['name' => 'db-1', 'ip' => '10.0.0.9'])]),
        'api.team.environments.servers.index' => paginatedRecords([serverRecord(['ip' => '10.0.0.1'])]),
    ]);

    $this->mock(ImportServerDatabase::class)->shouldReceive('handle')->once()
        ->withArgs(fn (array $database, string $environment) => $database['name'] === 'habits' && $environment === 'habits-production');

    [$code, $output] = runCommand('db:import', ['environment' => 'habits-production']);

    expect($code)->toBe(0)->and($output)->toContain('habits → local habits');

    $mock->assertNotSent(fn ($request, $response) => array_key_exists('search', $response->getPendingRequest()->query()->all()));
});

it('asks which database to import when there are several', function (): void {
    fakeImportApi([
        importDatabaseRecord('shop', 'mysql_native', ['name' => 'db-1', 'ip' => '10.0.0.9']),
        importDatabaseRecord('ledger', 'pgsql_native', ['name' => 'db-2', 'ip' => '10.0.0.10']),
    ]);

    $this->mock(ImportServerDatabase::class)->shouldReceive('handle')->once()->withArgs(fn (array $database) => $database['name'] === 'ledger');

    $this->artisan('db:import', ['environment' => 'shop-production'])
        ->expectsChoice('Which database do you want to import?', '#1', [
            '#0' => 'shop · MySQL · on db-1 (10.0.0.9)',
            '#1' => 'ledger · PostgreSQL · on db-2 (10.0.0.10)',
        ])
        ->expectsOutputToContain('ledger → local ledger')
        ->assertSuccessful();
});

it('names the databases a script can choose from when it cannot ask', function (): void {
    fakeImportApi([
        importDatabaseRecord('shop', 'mysql_native', ['name' => 'db-1', 'ip' => '10.0.0.9']),
        importDatabaseRecord('ledger', 'pgsql_native', ['name' => 'db-2', 'ip' => '10.0.0.10']),
    ]);

    $this->mock(ImportServerDatabase::class)->shouldNotReceive('handle');

    [$code, $output] = runCommand('db:import', ['environment' => 'shop-production', '--no-interaction' => true]);

    expect($code)->toBe(1)->and($output)->toContain('several databases: shop, ledger', '--database=<name> or --all');
});

it('refuses when only external databases are attached', function (): void {
    fakeImportApi([importDatabaseRecord('events', 'tinybird', null), importDatabaseRecord('cache', 'aws_rds', null)]);

    $this->mock(ImportServerDatabase::class)->shouldNotReceive('handle');

    [$code, $output] = runCommand('db:import', ['environment' => 'shop-production']);

    expect($code)->toBe(1)->and($output)->toContain('has no MySQL or PostgreSQL database on one of your servers');
});

it('says so when no team has the environment', function (): void {
    fakeImportApi([]);

    [$code, $output] = runCommand('db:import', ['environment' => 'nowhere', '--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error'])->toMatchArray(['status' => 404, 'message' => 'No environment `nowhere` in any of your teams.']);
});

it('dumps each engine the way its server allows, escaping every value', function (): void {
    $action = new ImportServerDatabase;

    expect($action->postgresDump('ledger'))->toBe("sudo -u postgres pg_dump --no-owner --no-acl 'ledger' | gzip")
        ->and($action->mysqlDump('shop', null))->toStartWith('sudo mysqldump -u root ')->toContain("'shop'")
        ->and($action->mysqlDump('shop', ['DB_USERNAME' => 'shop', 'DB_PASSWORD' => "it's"]))->toStartWith("MYSQL_PWD='it'\\''s' mysqldump --host=127.0.0.1 --user='shop'")
        ->and($action->pipeline('10.0.0.9', 'dump', 'import'))->toBe("set -o pipefail; ssh -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR -o ServerAliveInterval=60 'rocketeer@10.0.0.9' 'dump' | gunzip | import");
});
