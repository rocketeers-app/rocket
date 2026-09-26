<?php

use App\Actions\InstallRocketVersion;
use App\Exceptions\StepException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['app.version' => '2.15.0']);
});

function fakeRocketTags(array $versions): void
{
    Http::fake(['api.github.com/repos/rocketeers-app/rocket/git/matching-refs/tags/v' => Http::response(
        array_map(fn (string $version): array => ['ref' => "refs/tags/{$version}"], $versions),
    )]);
}

it('says so when Rocket is on the latest version', function (): void {
    fakeRocketTags(['v2.14.2', 'v2.15.0']);

    $this->artisan('self-update')
        ->expectsOutputToContain('Rocket 2.15.0 is the latest version.')
        ->doesntExpectOutputToContain('Updated')
        ->assertSuccessful();
});

it('updates to the highest stable version, compared as versions', function (): void {
    fakeRocketTags(['v2.9.0', 'v2.15.0', 'v2.16.0', 'v2.100.1', 'v3.0.0-beta1', 'latest']);

    $installer = Mockery::mock(InstallRocketVersion::class);
    $installer->shouldReceive('pharPath')->andReturn('/usr/local/bin/rocket');
    $installer->shouldReceive('download')->once()->with('2.100.1', '/usr/local/bin/rocket')->andReturn('/usr/local/bin/.rocket.2.100.1.download');
    $installer->shouldReceive('replace')->once()->with('/usr/local/bin/.rocket.2.100.1.download', '/usr/local/bin/rocket');
    app()->instance(InstallRocketVersion::class, $installer);

    [$code, $output] = runCommand('self-update', ['--json' => true]);

    expect($code)->toBe(0)->and(json_decode($output, true))->toBe([
        'current' => '2.15.0',
        'latest' => '2.100.1',
        'updated' => true,
        'path' => '/usr/local/bin/rocket',
    ]);
});

it('prints one line after updating', function (): void {
    fakeRocketTags(['v2.15.1']);

    $installer = Mockery::mock(InstallRocketVersion::class);
    $installer->shouldReceive('pharPath')->andReturn('/usr/local/bin/rocket');
    $installer->shouldReceive('download')->andReturn('/usr/local/bin/.rocket.2.15.1.download');
    $installer->shouldReceive('replace');
    app()->instance(InstallRocketVersion::class, $installer);

    $this->artisan('self-update')
        ->expectsOutputToContain('Updated Rocket from 2.15.0 to 2.15.1')
        ->assertSuccessful();
});

it('explains when GitHub cannot be read', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403)]);

    [$code, $output] = runCommand('self-update', ['--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error']['message'])->toBe('Could not check for a new version: GitHub answered HTTP 403.');
});

it('refuses to update when it does not run from a PHAR', function (): void {
    fakeRocketTags(['v2.15.1']);

    [$code, $output] = runCommand('self-update', ['--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error']['message'])->toContain('Only an installed Rocket can update itself');
});

it('replaces the PHAR with a download that runs as the version', function (): void {
    $directory = sys_get_temp_dir().'/rocket-self-update-'.uniqid();
    mkdir($directory);
    file_put_contents("{$directory}/rocket", 'old');
    chmod("{$directory}/rocket", 0755);

    Http::fake(['raw.githubusercontent.com/rocketeers-app/rocket/v2.15.1/builds/rocket' => Http::response('<?php echo "Rocket 2.15.1\\n";')]);

    $installer = new InstallRocketVersion;
    $download = $installer->download('2.15.1', "{$directory}/rocket");
    $installer->replace($download, "{$directory}/rocket");

    expect(file_get_contents("{$directory}/rocket"))->toContain('Rocket 2.15.1')
        ->and(fileperms("{$directory}/rocket") & 0777)->toBe(0755)
        ->and(glob("{$directory}/.rocket*"))->toBe([]);

    (new Filesystem)->deleteDirectory($directory);
});

it('keeps the PHAR when the download does not run as the version', function (): void {
    $directory = sys_get_temp_dir().'/rocket-self-update-'.uniqid();
    mkdir($directory);
    file_put_contents("{$directory}/rocket", 'old');

    Http::fake(['raw.githubusercontent.com/*' => Http::response('<html>Not Found</html>')]);

    expect(fn () => (new InstallRocketVersion)->download('2.15.1', "{$directory}/rocket"))->toThrow(StepException::class, 'does not run as Rocket 2.15.1')
        ->and(file_get_contents("{$directory}/rocket"))->toBe('old')
        ->and(glob("{$directory}/.rocket*"))->toBe([]);

    (new Filesystem)->deleteDirectory($directory);
});
