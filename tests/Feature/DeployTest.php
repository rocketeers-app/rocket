<?php

use App\Api\Requests\GetApiDocs;
use App\Schema\SchemaCache;
use App\Support\DeploymentRenderer;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;

beforeEach(function (): void {
    actingInTeam(['environments:read', 'deployments:read', 'deployments:create']);
    config(['rocketeers.deployment_poll_interval' => 0]);

    $this->requests = [];
});

function deployStep(int $id, string $title, string $status, ?string $server = 'web-1', ?float $duration = 2.0): array
{
    return ['id' => $id, 'title' => $title, 'status' => $status, 'duration' => $status === 'running' ? null : $duration, 'started_at' => now()->subSeconds(3)->toIso8601String(), 'server' => $server === null ? null : ['id' => "srv-{$server}", 'name' => $server]];
}

function deployment(array $overrides = []): array
{
    return ['id' => 'dep-1', 'branch' => 'main', 'short_hash' => '5f5cdc1', 'started_at' => now()->toIso8601String(), 'completed_at' => null, 'failed_at' => null, 'cancelled_at' => null, 'failure_reason' => null, 'human_duration' => null, ...$overrides];
}

function deployApi(object $test, array $polls, array $servers = ['web-1', 'web-2'], array $extra = []): void
{
    $records = fn (array $records): MockResponse => MockResponse::make(['data' => $records, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($records)]]);
    $poll = 0;

    fakeApi([
        'api.team.environments.index' => $records([environmentRecord(['id' => 'env-1', 'slug' => 'acme-production'])]),
        'api.team.environments.deploy' => function (PendingRequest $pending) use ($test): MockResponse {
            $test->requests[] = 'deploy';

            return MockResponse::make(['data' => deployment(['started_at' => null])], 201);
        },
        'api.team.environments.deployments.steps' => function (PendingRequest $pending) use ($test, $polls, $servers, &$poll): MockResponse {
            $test->requests[] = 'steps:'.$pending->getRequest()->resolveEndpoint();
            [$deployment, $steps] = $polls[min($poll++, count($polls) - 1)];

            return MockResponse::make(['data' => $steps, 'deployment' => $deployment, 'servers' => array_map(fn (string $name): array => ['id' => "srv-{$name}", 'name' => $name], $servers)]);
        },
        ...$extra,
    ]);
}

it('deploys and follows every step on every server until the deployment is done', function (): void {
    deployApi($this, [
        [deployment(['started_at' => null]), []],
        [deployment(), [deployStep(1, 'Cloning the repository', 'success'), deployStep(2, 'Cloning the repository', 'running', 'web-2')]],
        [deployment(), [deployStep(1, 'Cloning the repository', 'success'), deployStep(2, 'Cloning the repository', 'success', 'web-2', 3.0), deployStep(3, 'Running migrations', 'running')]],
        [deployment(['completed_at' => now()->toIso8601String(), 'human_duration' => '41s']), [deployStep(1, 'Cloning the repository', 'success'), deployStep(2, 'Cloning the repository', 'success', 'web-2', 3.0), deployStep(3, 'Running migrations', 'success', 'web-1', 0.4)]],
    ]);

    [$code, $output] = runCommand('deploy', ['environment' => 'acme-production']);

    expect($code)->toBe(0)
        ->and($this->requests[0])->toBe('deploy')
        ->and($this->requests[1])->toBe('steps:/acme/environments/env-1/deployments/dep-1/steps')
        ->and($output)->toContain('Deploying acme-production (main · 5f5cdc1) to web-1, web-2', 'Waiting for the deployment before it to finish', 'Deployed acme-production in 41s')
        ->and($output)->toMatch('/\[web-1\] Cloning the repository \.+ 2s DONE/')
        ->and($output)->toMatch('/\[web-2\] Cloning the repository \.+ 3s DONE/')
        ->and($output)->toMatch('/\[web-1\] Running migrations \.+ 400ms DONE/')
        ->not->toContain('RUNNING')
        ->and(substr_count($output, 'Cloning the repository'))->toBe(2);
});

it('leaves the server out when the environment has one', function (): void {
    deployApi($this, [
        [deployment(['completed_at' => now()->toIso8601String()]), [deployStep(1, 'Cloning the repository', 'success')]],
    ], servers: ['web-1']);

    [, $output] = runCommand('deploy', ['environment' => 'acme-production']);

    expect($output)->toMatch('/  Cloning the repository \.+ 2s DONE/')->not->toContain('[web-1]');
});

it('shows the step that failed and why, and exits with an error', function (): void {
    deployApi($this, [
        [deployment(['failed_at' => now()->toIso8601String(), 'failure_reason' => 'Running migrations failed on web-1 (exit code 1)']), [deployStep(1, 'Cloning the repository', 'success'), deployStep(2, 'Running migrations', 'failed')]],
    ]);

    [$code, $output] = runCommand('deploy', ['environment' => 'acme-production']);

    expect($code)->toBe(1)
        ->and($output)->toMatch('/\[web-1\] Running migrations \.+ 2s FAIL/')
        ->and($output)->toContain('The deployment failed: Running migrations failed on web-1 (exit code 1)');
});

it('says so when the deployment is cancelled', function (): void {
    deployApi($this, [[deployment(['cancelled_at' => now()->toIso8601String()]), []]]);

    [$code, $output] = runCommand('deploy', ['environment' => 'acme-production']);

    expect($code)->toBe(1)->and($output)->toContain('The deployment was cancelled.');
});

it('prints one JSON document with the deployment and its steps under --json', function (): void {
    deployApi($this, [
        [deployment(), [deployStep(1, 'Cloning the repository', 'running')]],
        [deployment(['completed_at' => '2026-09-27T10:00:00+00:00']), [deployStep(1, 'Cloning the repository', 'success')]],
    ]);

    [$code, $output] = runCommand('deploy', ['environment' => 'acme-production', '--json' => true]);
    $json = json_decode($output, true);

    expect($code)->toBe(0)
        ->and($json['environment'])->toBe('acme-production')
        ->and($json['deployment']['completed_at'])->toBe('2026-09-27T10:00:00+00:00')
        ->and(array_column($json['steps'], 'status'))->toBe(['success'])
        ->and($json['stopped_following'])->toBeFalse();
});

it('does not start a deployment it cannot follow, after refreshing the schema once', function (): void {
    $bundled = json_decode((string) file_get_contents(SchemaCache::bundledPath()), true);
    $bundled['operations'] = array_values(array_filter($bundled['operations'], fn (array $operation): bool => $operation['name'] !== 'api.team.environments.deployments.steps'));
    Storage::put(SchemaCache::PATH, (string) json_encode([...$bundled, 'fetched_at' => time()]));
    app(SchemaCache::class)->flush();

    deployApi($this, [[deployment(['completed_at' => now()->toIso8601String()]), []]], extra: [
        GetApiDocs::class => function (): MockResponse {
            $this->requests[] = 'docs';

            return MockResponse::make(['message' => 'down'], 503);
        },
    ]);

    [$code, $output] = runCommand('deploy', ['environment' => 'acme-production', '--json' => true]);

    expect($code)->toBe(1)
        ->and($this->requests)->toBe(['docs'])
        ->and(json_decode($output, true)['error']['message'])->toContain('cannot follow a deployment yet', 'rocket deploy --detach');
});

it('only starts the deployment with --detach', function (): void {
    deployApi($this, [[deployment(), []]]);

    [$code, $output] = runCommand('deploy', ['environment' => 'acme-production', '--detach' => true]);

    expect($code)->toBe(0)
        ->and($this->requests)->toBe(['deploy'])
        ->and($output)->toContain('Follow it with `rocket deployments:follow acme-production`');
});

it('follows the latest deployment when none is named', function (): void {
    deployApi($this, [[deployment(['id' => 'dep-9', 'completed_at' => now()->toIso8601String()]), [deployStep(1, 'Cloning the repository', 'success')]]], extra: [
        'api.team.environments.deployments.index' => MockResponse::make(['data' => [['id' => 'dep-9'], ['id' => 'dep-8']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 2]]),
    ]);

    [$code] = runCommand('deployments:follow', ['environment' => 'acme-production']);

    expect($code)->toBe(0)
        ->and($this->requests)->toBe(['steps:/acme/environments/env-1/deployments/dep-9/steps']);
});

it('keeps the running steps below the finished ones in a terminal, as RUNNING lines', function (): void {
    $stream = fopen('php://memory', 'w+');
    $output = new class($stream) extends ConsoleOutput
    {
        public function __construct($stream)
        {
            StreamOutput::__construct($stream, self::VERBOSITY_NORMAL, true);
        }
    };

    $renderer = new DeploymentRenderer($output, 'acme-production', live: true);
    $renderer->update(deployment(), [deployStep(1, 'Cloning the repository', 'running'), deployStep(2, 'Cloning the repository', 'running', 'web-2')], [['name' => 'web-1'], ['name' => 'web-2']]);
    $renderer->update(deployment(), [deployStep(1, 'Cloning the repository', 'success'), deployStep(2, 'Cloning the repository', 'running', 'web-2')], [['name' => 'web-1'], ['name' => 'web-2']]);
    $renderer->finish();

    rewind($stream);
    $written = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', (string) stream_get_contents($stream));

    expect($written)->toMatch('/\[web-1\] Cloning the repository \.+ 3s RUNNING/')
        ->and($written)->toMatch('/\[web-2\] Cloning the repository \.+ 3s RUNNING/')
        ->and($written)->toMatch('/\[web-1\] Cloning the repository \.+ 2s DONE/');
});
