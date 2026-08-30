<?php

use App\Commands\Concerns\ValidatesSiteArguments;

beforeEach(function () {
    $this->subject = new class
    {
        use ValidatesSiteArguments;

        public function check(string $value): bool
        {
            return $this->isSafeIdentifier($value);
        }
    };
});

it('accepts plain hostname-like identifiers', function (string $value) {
    expect($this->subject->check($value))->toBeTrue();
})->with([
    'example',
    'example.com',
    'my-site',
    'my_site',
    'staging2',
    'a',
    '123',
]);

it('rejects shell metacharacters and unsafe forms', function (string $value) {
    expect($this->subject->check($value))->toBeFalse();
})->with([
    'site; rm -rf /',
    'site && echo pwned',
    'site`whoami`',
    'site$(whoami)',
    'site|cat /etc/passwd',
    'site with spaces',
    "site\nwith-newline",
    'site/with/slash',
    "site'quote",
    'site"quote',
    '',
    '-leading-hyphen',
    '.leading-dot',
]);
