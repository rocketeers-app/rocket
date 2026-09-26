<?php

namespace App\Schema;

use Illuminate\Support\Str;

/**
 * Names a command after its route: `api.team.environments.daemons.index` becomes
 * `environments:daemons:list`. A list route without a verb (`api.team.daemons`) gains `list`.
 */
class CommandNamer
{
    private const array VERBS = [
        'index' => 'list',
        'show' => 'read',
        'store' => 'create',
        'update' => 'update',
        'destroy' => 'delete',
    ];

    public static function name(string $routeName, string $envelope = 'none'): string
    {
        $segments = explode('.', Str::after($routeName, 'api.team.'));
        $last = array_key_last($segments);

        if (isset(self::VERBS[$segments[$last]])) {
            $segments[$last] = self::VERBS[$segments[$last]];
        } elseif ($envelope === 'paginated') {
            $segments[] = 'list';
        }

        return implode(':', $segments);
    }
}
