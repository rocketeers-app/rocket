<?php

namespace App\Support;

/** Reads a server record from the API: the address Rocket connects to over SSH. */
class Servers
{
    /** @param array<string, mixed> $server */
    public static function host(array $server): ?string
    {
        foreach (['ip', 'ipv4_address', 'floating_ip_address'] as $key) {
            if (filled($server[$key] ?? null)) {
                return (string) $server[$key];
            }
        }

        return null;
    }
}
