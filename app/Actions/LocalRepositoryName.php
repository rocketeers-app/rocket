<?php

namespace App\Actions;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/** The local directory name of a repository, from its clone URL: `git@github.com:acme/monorepo.git` becomes `monorepo`. */
class LocalRepositoryName
{
    use AsAction;

    public function handle(string $url): string
    {
        $path = Str::afterLast(rtrim(trim($url), '/'), '/');
        $path = Str::afterLast($path, ':');

        return Str::endsWith($path, '.git') ? Str::beforeLast($path, '.git') : $path;
    }
}
