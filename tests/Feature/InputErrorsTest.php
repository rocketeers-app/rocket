<?php

use App\Api\Requests\GetApiDocs;
use App\Api\Requests\GetMe;
use App\Api\Requests\GetMyTeams;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(fn () => actingInTeam());

function rocket(string ...$arguments): array
{
    $output = new BufferedOutput;
    $code = app(Kernel::class)->handle(new ArgvInput(['rocket', ...$arguments]), $output);

    return [$code, $output->fetch()];
}

it('suggests the command you meant instead of handing it to the default command', function (): void {
    [$code, $output] = rocket('setupt-token', '-n');

    expect($code)->toBe(1)
        ->and($output)->toContain('ERROR  Rocket has no `setupt-token` command.', 'Did you mean rocket setup-token?')
        ->not->toContain('No arguments expected');
});

it('lists the commands of a group you named instead of a command', function (): void {
    [$code, $output] = rocket('env', '-n');

    expect($code)->toBe(1)->and($output)->toContain('Rocket has no `env` command.', 'Did you mean one of these?', '• rocket env:pull', '• rocket env:edit');
});

it('points to the command list when nothing comes close', function (): void {
    [$code, $output] = rocket('xyzzy', '-n');

    expect($code)->toBe(1)->and($output)->toContain('Rocket has no `xyzzy` command.', 'Run rocket to see every command.');
});

it('reports an unknown command as JSON under --json', function (): void {
    [$code, $output] = rocket('setupt-token', '--json');

    expect($code)->toBe(1)->and(json_decode($output, true))->toBe(['error' => [
        'status' => 404,
        'message' => 'Rocket has no `setupt-token` command.',
        'alternatives' => ['setup-token'],
    ]]);
});

it('runs the command you meant when you say so', function (): void {
    fakeApi([
        GetMe::class => MockResponse::make(['data' => ['id' => 'u1', 'name' => 'Ada', 'email' => 'ada@example.com']]),
        GetMyTeams::class => MockResponse::make(['data' => [['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme']]]),
        GetApiDocs::class => MockResponse::make([], 500),
    ]);
    $asked = null;
    Prompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(function (ConfirmPrompt $prompt) use (&$asked): bool {
        $asked = $prompt->label;

        return true;
    });

    [$code, $output] = rocket('setupt-token', 'new-token');

    expect($asked)->toBe('Rocket has no `setupt-token` command. Did you mean `rocket setup-token`?')
        ->and($code)->toBe(0)
        ->and($output)->toContain('Authenticated as Ada (ada@example.com).');
});

it('explains a mistake in the arguments or options with the usage of the command', function (array $arguments, string $message): void {
    [$code, $output] = rocket(...$arguments);

    expect($code)->toBe(1)
        ->and($output)->toContain("ERROR  {$message}", 'Usage: rocket '.$arguments[0], 'Run rocket '.$arguments[0].' --help to see every argument and option.')
        ->not->toContain('RuntimeException');
})->with([
    'unknown option' => [['deploy', '--foo'], '`rocket deploy` has no `--foo` option.'],
    'mistyped option' => [['deploy', '--detatch'], '`rocket deploy` has no `--detatch` option. Did you mean `--detach`?'],
    'value for a flag' => [['deploy', '--detach=1'], '`--detach` takes no value; pass just `--detach`.'],
    'option without its value' => [['clients:create', '--name'], '`--name` needs a value: `--name=…`.'],
    'argument for a command without any' => [['me', 'extra'], '`rocket me` takes no arguments, but got `extra`.'],
    'too many arguments' => [['deployments:follow', 'a', 'b', 'c'], 'Too many arguments: `rocket deployments:follow` takes <environment> <deployment>.'],
]);

it('reports a mistake in the options as JSON under --json', function (): void {
    [$code, $output] = rocket('deploy', '--foo', '--json');

    expect($code)->toBe(1)->and(json_decode($output, true))->toBe(['error' => [
        'status' => 422,
        'message' => '`rocket deploy` has no `--foo` option.',
        'usage' => 'rocket deploy [options] [--] [<environment>]',
    ]]);
});

it('still runs the default command without a command name', function (): void {
    [$code, $output] = rocket('--json');

    expect($code)->toBe(0)->and(json_decode($output, true))->toHaveKeys(['version', 'resources']);
});

it('stops without repeating itself when you do not mean the suggested command', function (): void {
    Prompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(fn (): bool => false);

    [$code, $output] = rocket('setupt-token', 'new-token');

    expect($code)->toBe(1)->and($output)->toBe('');
});
