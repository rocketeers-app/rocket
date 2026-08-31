<?php

use App\Actions\DetectRemoteSite;

function callDetectRemoteSiteMethod(string $method, ...$args)
{
    $reflection = new ReflectionMethod(DetectRemoteSite::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(new DetectRemoteSite, ...$args);
}

it('extracts each KEY=value line from the remote detection script output', function () {
    $output = implode("\n", [
        'IS_WORDPRESS=yes',
        'IS_BEDROCK=no',
        'REPO_URL=git@github.com:acme/example-app.git',
        'BRANCH=main',
    ]);

    expect(callDetectRemoteSiteMethod('extract', $output, 'IS_WORDPRESS'))->toBe('yes')
        ->and(callDetectRemoteSiteMethod('extract', $output, 'IS_BEDROCK'))->toBe('no')
        ->and(callDetectRemoteSiteMethod('extract', $output, 'REPO_URL'))->toBe('git@github.com:acme/example-app.git')
        ->and(callDetectRemoteSiteMethod('extract', $output, 'BRANCH'))->toBe('main');
});

it('returns an empty string for a missing key', function () {
    expect(callDetectRemoteSiteMethod('extract', 'IS_WORDPRESS=yes', 'BRANCH'))->toBe('');
});

it('trims surrounding whitespace from the extracted value', function () {
    expect(callDetectRemoteSiteMethod('extract', "BRANCH=  main  \n", 'BRANCH'))->toBe('main');
});

it('derives the repository name from the repository URL when one was found', function () {
    $name = callDetectRemoteSiteMethod(
        'deriveRepositoryName',
        'git@github.com:acme/example-app.git',
        'example-app-prod'
    );

    expect($name)->toBe('example-app');
});

it('falls back to stripping a trailing "-word" suffix off the site alias when there is no repository URL', function () {
    // Naive by design (matches the original GetRepositoryName behaviour):
    // it strips whatever trailing "-[a-z]+" is there, so "site-prod" ->
    // "site", but also "example-app" -> "example". Only used as a last
    // resort when the remote has no git repository to ask instead.
    expect(callDetectRemoteSiteMethod('deriveRepositoryName', '', 'example-prod'))->toBe('example')
        ->and(callDetectRemoteSiteMethod('deriveRepositoryName', '', 'example'))->toBe('example');
});
