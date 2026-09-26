<?php

namespace App\Actions;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/** The local name of an environment: its slug without the trailing label, so `routine-production` becomes `routine`. */
class LocalProjectName
{
    use AsAction;

    public function handle(array $environment): string
    {
        $slug = (string) $environment['slug'];
        $label = (string) ($environment['label'] ?? '');

        if ($label === '' || ! Str::endsWith($slug, "-{$label}")) {
            return $slug;
        }

        return Str::beforeLast($slug, "-{$label}") ?: $slug;
    }
}
