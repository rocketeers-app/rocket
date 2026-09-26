<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

/** Sets keys in the contents of an env file: replaces the line of a key that is there, appends one that is not. */
class SetEnvValues
{
    use AsAction;

    public function handle(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = "{$key}={$value}";
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents) === 1
                ? preg_replace_callback($pattern, fn (): string => $line, $contents)
                : rtrim($contents, "\n").($contents === '' ? '' : "\n").$line."\n";
        }

        return $contents;
    }
}
