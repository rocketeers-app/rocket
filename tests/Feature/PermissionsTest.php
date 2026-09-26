<?php

use Saloon\Http\Faking\MockResponse;

it('stops before the request when the team does not allow the operation', function (): void {
    actingInTeam(['servers:read']);
    $mock = fakeApi([]);

    [$code, $output] = runCommand('servers:reboot', ['server' => 'web-1', '--force' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('No access to servers:reboot in team Acme. Requires: Reboot Servers (servers:reboot).');

    $mock->assertNothingSent();
});

it('marks what the team does not allow in the command list', function (): void {
    actingInTeam(['servers:read']);

    [, $output] = runCommand('list');

    expect($output)->toMatch('/servers:reboot\s+Reboot\s+🔒 no access/')
        ->and($output)->not->toMatch('/servers:list\s+List Servers\s+🔒/');
});

it('explains a refusal from the server the same way when the cached permissions are stale', function (): void {
    actingInTeam();
    fakeApi([
        'api.team.servers.index' => MockResponse::make(['data' => [serverRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.servers.reboot' => MockResponse::make(['message' => 'Your role in this team does not include servers:reboot.', 'code' => 'insufficient_permission', 'missing_permissions' => ['servers:reboot']], 403),
    ]);

    [$code, $output] = runCommand('servers:reboot', ['server' => 'web-1', '--force' => true]);

    expect($code)->toBe(1)->and($output)->toContain('No access to servers:reboot in team Acme. Requires: Reboot Servers (servers:reboot).');
});

it('asks before a destructive action and names what it acts on', function (): void {
    actingInTeam();
    $mock = fakeApi([
        'api.team.servers.index' => MockResponse::make(['data' => [serverRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
    ]);

    $this->artisan('servers:reboot', ['server' => 'web-1'])
        ->expectsConfirmation('Reboot web-1?', 'no')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertSuccessful();

    $mock->assertSentCount(1);
});

it('goes ahead with --force and reports the queued action', function (): void {
    actingInTeam();
    $mock = fakeApi([
        'api.team.servers.index' => MockResponse::make(['data' => [serverRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.servers.reboot' => MockResponse::make(['message' => 'Rebooting.'], 202),
    ]);

    [$code, $output] = runCommand('servers:reboot', ['server' => 'web-1', '--force' => true]);

    expect($code)->toBe(0)->and($output)->toContain('✓ Queued: Reboot web-1');
});

it('refuses a destructive action without --force when nobody can confirm', function (): void {
    actingInTeam();
    fakeApi([
        'api.team.servers.index' => MockResponse::make(['data' => [serverRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
    ]);

    [$code, $output] = runCommand('servers:reboot', ['server' => 'web-1', '--no-interaction' => true]);

    expect($code)->toBe(1)->and($output)->toContain('Pass --force to go ahead');
});
