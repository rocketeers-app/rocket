<?php

use App\Commands\ListSites;

function parseSitesFromSshConfig(string $config): array
{
    $command = new ListSites;

    $method = new ReflectionMethod(ListSites::class, 'parseSites');
    $method->setAccessible(true);

    return $method->invoke($command, $config);
}

it('pairs Host/HostName entries inside the Rocketeers-managed block', function () {
    $config = <<<'SSH'
    Host somewhere-else
        HostName 10.0.0.1

    ### ROCKETEERS APP ###

    Host acme-prod
        HostName 203.0.113.10
        User rocketeer

    Host acme-staging
        HostName 203.0.113.11
        User rocketeer

    ### ROCKETEERS APP ###
    SSH;

    expect(parseSitesFromSshConfig($config))->toBe([
        'acme-prod' => '203.0.113.10',
        'acme-staging' => '203.0.113.11',
    ]);
});

it('ignores hosts outside the Rocketeers-managed block', function () {
    $config = "Host somewhere-else\n    HostName 10.0.0.1";

    expect(parseSitesFromSshConfig($config))->toBe([]);
});

it('returns an empty array for an empty config', function () {
    expect(parseSitesFromSshConfig(''))->toBe([]);
});

it('handles a Host with no HostName line', function () {
    $config = "### ROCKETEERS APP ###\nHost acme-prod\n### ROCKETEERS APP ###";

    expect(parseSitesFromSshConfig($config))->toBe(['acme-prod' => null]);
});
