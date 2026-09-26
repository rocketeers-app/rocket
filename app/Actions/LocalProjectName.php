<?php

namespace App\Actions;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The local name of an environment: the last part of its root directory in a monorepo (`apps/api` becomes `api`),
 * else its slug without the trailing label, so `routine-production` becomes `routine`.
 */
class LocalProjectName
{
    use AsAction;

    public function handle(array $environment): string
    {
        $rootDirectory = trim((string) ($environment['root_directory'] ?? ''), '/');

        if ($rootDirectory !== '') {
            return basename($rootDirectory);
        }

        $slug = (string) $environment['slug'];
        $label = (string) ($environment['label'] ?? '');

        if ($label === '' || ! Str::endsWith($slug, "-{$label}")) {
            return $slug;
        }

        return Str::beforeLast($slug, "-{$label}") ?: $slug;
    }
}
