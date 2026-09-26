<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Saloon\Http\Faking\MockClient;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    public const string HOME = __DIR__.'/.home';

    protected function setUp(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory(self::HOME);
        $files->ensureDirectoryExists(self::HOME.'/.rocketeers');
        $files->put(self::HOME.'/.rocketeers/.env', implode(PHP_EOL, [
            'API_TOKEN=test-token',
            'API_URL=https://api.rocketeers.test/v1',
            'DEFAULT_TEAM=acme',
            'ROCKET_AUTO_REFRESH=false',
        ]).PHP_EOL);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }
}
