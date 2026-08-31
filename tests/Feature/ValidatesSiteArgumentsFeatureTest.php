<?php

/**
 * Every command taking {site}/--server rejects an unsafe value before doing
 * anything else - no SSH connection is ever attempted for these, so this is
 * safe to run with no network and no real site/server.
 */
it('rejects an unsafe site argument', function (string $command, array $arguments) {
    $this->artisan($command, ['site' => 'evil; rm -rf /'] + $arguments)
        ->assertExitCode(1);
})->with([
    ['install', []],
    ['sync', ['--force' => true]],
    ['db:import', ['--force' => true]],
    ['tail', []],
    ['remove', ['--force' => true]],
]);

it('rejects an unsafe --server option', function () {
    $this->artisan('sync', ['site' => 'my-site', '--server' => 'evil; rm -rf /', '--force' => true])
        ->assertExitCode(1);
});
