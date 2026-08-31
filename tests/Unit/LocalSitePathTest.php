<?php

use App\Support\LocalSitePath;

it('defaults to /var/www', function () {
    expect(LocalSitePath::for('my-site'))->toBe('/var/www/my-site');
});

it('respects the configured sites_path', function () {
    config(['rocketeers.sites_path' => '/home/dev/Sites']);

    expect(LocalSitePath::for('my-site'))->toBe('/home/dev/Sites/my-site');
});

it('strips a trailing slash from a configured sites_path', function () {
    config(['rocketeers.sites_path' => '/home/dev/Sites/']);

    expect(LocalSitePath::for('my-site'))->toBe('/home/dev/Sites/my-site');
});
