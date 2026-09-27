<?php

return [
    'api_token' => env('API_TOKEN'),

    'api_url' => env('API_URL', 'https://api.rocketeersapp.com/v1'),

    'default_team' => env('DEFAULT_TEAM'),

    'auto_refresh' => (bool) env('ROCKET_AUTO_REFRESH', true),

    'schema_max_age' => 60 * 60 * 24,

    'teams_max_age' => 60 * 10,

    'local_pgsql_user' => env('LOCAL_PGSQL_USER', 'root'),

    'projects_path' => env('ROCKET_PROJECTS_PATH', '/var/www'),

    'deployment_poll_interval' => (int) env('ROCKET_DEPLOYMENT_POLL_INTERVAL', 1000),
];
