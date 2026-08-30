<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->fakeHome = sys_get_temp_dir().'/rocket-test-home-'.uniqid();
    mkdir($this->fakeHome.'/.ssh', 0755, true);

    $this->originalHome = getenv('HOME');
    putenv('HOME='.$this->fakeHome);
});

afterEach(function () {
    putenv('HOME='.$this->originalHome);

    exec('rm -rf '.escapeshellarg($this->fakeHome));
});

it('lists sites found in the Rocketeers-managed ssh config block', function () {
    file_put_contents($this->fakeHome.'/.ssh/config', implode("\n", [
        'Host somewhere-else',
        '    HostName 10.0.0.1',
        '',
        '### ROCKETEERS APP ###',
        '',
        'Host acme-prod',
        '    HostName 203.0.113.10',
        '',
        '### ROCKETEERS APP ###',
        '',
    ]));

    // A table row prints "acme-prod" and "203.0.113.10" on the same
    // output line, and Laravel's expectsOutputToContain() only lets the
    // first matching expectation claim a given write - a second
    // expectation for a substring on that same line never gets its
    // chance. Asserting against the full captured buffer sidesteps that.
    $exitCode = Artisan::call('sites');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('acme-prod')
        ->and($output)->toContain('203.0.113.10')
        ->and($output)->not->toContain('somewhere-else');
});

it('points to ssh:config when nothing is configured yet', function () {
    $this->artisan('sites')
        ->expectsOutputToContain('rocket ssh:config')
        ->assertExitCode(0);
});
