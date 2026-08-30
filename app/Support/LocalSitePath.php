<?php

namespace App\Support;

class LocalSitePath
{
    /**
     * The local install path for a site, e.g. "/var/www/my-site". Backed by
     * config('rocketeers.sites_path') rather than a hardcoded "/var/www" so
     * it can be overridden per machine (see config/rocketeers.php).
     */
    public static function for(string $name): string
    {
        return rtrim(config('rocketeers.sites_path'), '/').'/'.$name;
    }
}
