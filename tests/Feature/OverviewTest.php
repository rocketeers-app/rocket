<?php

it('shows every resource and the local commands when run without a command', function (): void {
    actingInTeam(['servers:read']);

    [$code, $output] = runCommand('home');

    expect($code)->toBe(0)
        ->and($output)->toContain('Acme', 'INFRASTRUCTURE', 'environments', 'servers', 'ON YOUR MACHINE', 'ssh:config', 'rocket hub')
        ->and(config('commands.default'))->toBe(App\Commands\Home::class);
});

it('says so when nobody is signed in', function (): void {
    config(['rocketeers.api_token' => null]);

    [, $output] = runCommand('home');

    expect($output)->toContain('not signed in', 'rocket setup-token');
});

it('lists the commands of one resource with a lock on what the team does not allow', function (): void {
    actingInTeam(['servers:read']);

    [$code, $output] = runCommand('servers');

    expect($code)->toBe(0)
        ->and($output)->toContain('servers:read {server}', 'Get Server', 'servers:reboot', '🔒 servers:reboot', 'CRON');
});
