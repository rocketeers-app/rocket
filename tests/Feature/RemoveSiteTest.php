<?php

beforeEach(function () {
    $this->sitesPath = sys_get_temp_dir().'/rocket-test-sites-'.uniqid();
    mkdir($this->sitesPath, 0755, true);
    config(['rocketeers.sites_path' => $this->sitesPath]);
});

afterEach(function () {
    if (is_dir($this->sitesPath)) {
        exec('rm -rf '.escapeshellarg($this->sitesPath));
    }
});

it('reports nothing to remove when the site directory does not exist', function () {
    $this->artisan('remove', ['site' => 'never-installed', '--force' => true])
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(0);
});

it('aborts without --force when the confirmation is declined', function () {
    mkdir($this->sitesPath.'/my-site');

    $question = "This will delete {$this->sitesPath}/my-site and drop the local 'my-site' database. Continue?";

    $this->artisan('remove', ['site' => 'my-site'])
        ->expectsConfirmation($question, 'no')
        ->expectsOutputToContain('Aborted')
        ->assertExitCode(0);

    expect(is_dir($this->sitesPath.'/my-site'))->toBeTrue();
});

it('rejects an unsafe --name option', function () {
    mkdir($this->sitesPath.'/my-site');

    $this->artisan('remove', ['site' => 'my-site', '--name' => 'evil; rm -rf /', '--force' => true])
        ->assertExitCode(1);

    expect(is_dir($this->sitesPath.'/my-site'))->toBeTrue();
});
