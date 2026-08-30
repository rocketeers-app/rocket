<?php

namespace App\Commands\Concerns;

trait ValidatesSiteArguments
{
    /**
     * Site and server values are interpolated into shell commands and remote
     * SSH scripts throughout the Actions layer. Restricting them to a safe
     * hostname-like charset here, once, means every downstream Action can
     * trust them instead of re-escaping at every call site.
     */
    protected function validateSiteAndServer(string $site, ?string $server = null): bool
    {
        if (! $this->isSafeIdentifier($site)) {
            $this->error("Invalid site \"{$site}\": only letters, numbers, dots, hyphens and underscores are allowed.");

            return false;
        }

        if ($server !== null && ! $this->isSafeIdentifier($server)) {
            $this->error("Invalid server \"{$server}\": only letters, numbers, dots, hyphens and underscores are allowed.");

            return false;
        }

        return true;
    }

    protected function isSafeIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]{0,253}[a-zA-Z0-9])?$/', $value);
    }
}
