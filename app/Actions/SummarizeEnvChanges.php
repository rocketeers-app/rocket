<?php

namespace App\Actions;

use Dotenv\Dotenv;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Names the keys an edit of an env file added, changed and removed, and never their values. A file dotenv cannot
 * parse (the API will say why), or parses only partly, as an unclosed quote makes it do, is compared line by line,
 * so the summary still shows what you touched.
 */
class SummarizeEnvChanges
{
    use AsAction;

    /** @return array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>} */
    public function handle(string $before, string $after): array
    {
        [$old, $new] = [$this->parse($before), $this->parse($after)];

        if ($old === null || $new === null) {
            [$old, $new] = [$this->lines($before), $this->lines($after)];
        }

        return [
            'added' => array_values(array_diff(array_keys($new), array_keys($old))),
            'changed' => array_values(array_filter(array_keys($new), fn (string $key): bool => array_key_exists($key, $old) && $old[$key] !== $new[$key])),
            'removed' => array_values(array_diff(array_keys($old), array_keys($new))),
        ];
    }

    /**
     * @param  array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>}  $changes
     * @return array<int, string>
     */
    public function keys(array $changes): array
    {
        return [...$changes['added'], ...$changes['changed'], ...$changes['removed']];
    }

    /** @return array<string, ?string>|null */
    private function parse(string $contents): ?array
    {
        try {
            $values = Dotenv::parse($contents);
        } catch (Throwable) {
            return null;
        }

        return array_diff(array_keys($this->lines($contents)), array_keys($values)) === [] ? $values : null;
    }

    /** @return array<string, string> */
    private function lines(string $contents): array
    {
        preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/m', $contents, $matches);

        return array_combine($matches[1], array_map('trim', $matches[2]));
    }
}
