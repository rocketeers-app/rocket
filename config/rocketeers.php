<?php

return [
    'api_token' => env('API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Local sites path
    |--------------------------------------------------------------------------
    |
    | Rocket clones/installs sites into "{sites_path}/{name}" on this
    | machine (Herd's and Valet's own parked-sites convention varies:
    | some setups use /var/www, others ~/Sites or ~/Herd). Override with
    | the ROCKET_SITES_PATH env var if your machine doesn't use /var/www.
    |
    */

    'sites_path' => env('ROCKET_SITES_PATH', '/var/www'),
];
