<?php

/*
|--------------------------------------------------------------------------
| Rocketeers API endpoint registry
|--------------------------------------------------------------------------
|
| Every GET endpoint the CLI can reach. Each resource maps to one discoverable
| `api:{key}` command. Routes are keyed by their "scope signature": the sorted,
| pipe-joined set of non-{team} path placeholders provided as flags.
|
|   ''             team-level list           rocket api:daemons --team=x
|   'id'           single resource show      rocket api:sites --team=x --id=42
|   'server'       scoped to a server        rocket api:daemons --team=x --server=uuid
|   'environment'  scoped to an environment  rocket api:daemons --team=x --environment=id
|   'domain'       scoped to a domain        rocket api:dns --team=x --domain=example.com
|   'environment|id'  nested show            rocket api:deployments --team=x --environment=id --id=dep
|
| Route value: a path string (envelope inferred — `single` when the signature
| contains `id`, otherwise `paginated`) OR ['path' => ..., 'envelope' => 'single'|'custom'].
|
| Column value: 'field' (plain, dot-notation allowed) OR ['field', 'format', ...args]
| where format is one of: money, bool, limit (arg: length), count.
|
| Endpoints intentionally reachable only via `rocket api:get <path>` are listed
| under `raw_only` so `api:check` classifies them instead of flagging them missing.
|
*/

return [

    'resources' => [

        'apps' => [
            'description' => 'List apps in a team',
            'ability' => 'apps:read',
            'columns' => [
                'Name' => 'name',
                'Environments' => ['environments_count', 'count'],
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/apps',
                'id' => '{team}/apps/{id}',
            ],
        ],

        'backups' => [
            // Response is grouped per environment (databaseBackups/fileBackups),
            // so App\Commands\Api\Backups renders its own flattened table.
            'description' => "List backups for a server's environments",
            'ability' => 'backups:read',
            'columns' => [],
            'routes' => [
                'server' => '{team}/servers/{server}/backups',
            ],
        ],

        'certificates' => [
            'description' => 'List certificates in a team or on a server',
            'ability' => 'certificates:read',
            'columns' => [
                'Name' => 'name',
                'Primary Domain' => 'primary_domain',
                'Issuer' => 'issuer',
                'Expires' => 'expiration_date',
            ],
            'routes' => [
                '' => '{team}/certificates',
                'server' => '{team}/servers/{server}/certificates',
            ],
        ],

        'clients' => [
            'description' => 'List clients in a team',
            'ability' => 'clients:read',
            'columns' => [
                'Name' => 'name',
                'Slug' => 'slug',
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/clients',
                'id' => '{team}/clients/{id}',
            ],
        ],

        'commands' => [
            'description' => 'List commands run in an environment',
            'ability' => 'commands:read',
            'columns' => [
                'Description' => ['description', 'limit', 40],
                'Exit Code' => 'exit_code',
                'Completed' => ['completed', 'bool'],
                'Created' => 'created_at',
            ],
            'routes' => [
                'environment' => '{team}/environments/{environment}/commands',
            ],
        ],

        'daemons' => [
            'description' => 'List daemons in a team, on a server, or in an environment',
            'ability' => 'daemons:read',
            'columns' => [
                'Name' => 'name',
                'Command' => 'command',
                'User' => 'username',
                'Processes' => ['processes', 'count'],
            ],
            'routes' => [
                '' => '{team}/daemons',
                'server' => '{team}/servers/{server}/daemons',
                'environment' => '{team}/environments/{environment}/daemons',
            ],
        ],

        'databases' => [
            'description' => 'List databases in a team, on a server, or in an environment',
            'ability' => 'databases:read',
            'columns' => [
                'Name' => 'name',
                'Type' => 'type',
                'Kind' => 'kind',
                'Replicas' => ['replicas', 'count'],
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/databases',
                'server' => '{team}/servers/{server}/databases',
                'environment' => '{team}/environments/{environment}/databases',
            ],
        ],

        'deployments' => [
            'description' => 'List deployments for an environment',
            'ability' => 'deployments:read',
            'columns' => [
                'Branch' => 'branch',
                'Commit' => 'short_hash',
                'Message' => ['commit_message', 'limit', 40],
                'Duration' => 'human_duration',
                'When' => 'time_ago',
            ],
            'routes' => [
                'environment' => '{team}/environments/{environment}/deployments',
                'environment|id' => '{team}/environments/{environment}/deployments/{id}',
            ],
        ],

        'domains' => [
            'description' => 'List domains in a team or on a server',
            'ability' => 'domains:read',
            'columns' => [
                'Name' => 'name',
                'FQDN' => 'fqdn',
                'WWW' => ['use_www', 'bool'],
                'HTTPS' => ['https_redirect', 'bool'],
                'Expires' => 'expires_at',
            ],
            'routes' => [
                '' => '{team}/domains',
                'id' => '{team}/domains/{id}',
                'server' => '{team}/servers/{server}/domains',
            ],
        ],

        'dns' => [
            'description' => 'List DNS records for a domain',
            'ability' => 'dns:read',
            'columns' => [],
            'routes' => [
                'domain' => ['path' => '{team}/domains/{domain}/dns', 'envelope' => 'custom'],
            ],
        ],

        'environments' => [
            'description' => 'List environments in a team',
            'ability' => 'environments:read',
            'columns' => [
                'Name' => 'name',
                'Type' => 'type',
                'PHP' => 'php_version',
                'Domain' => 'domain',
                'Auto Deploy' => ['auto_deploy', 'bool'],
            ],
            'routes' => [
                '' => '{team}/environments',
                'id' => '{team}/environments/{id}',
            ],
        ],

        'errors' => [
            'description' => 'List errors in a team or an environment',
            'ability' => 'errors:read',
            'columns' => [
                'Message' => ['message', 'limit', 50],
                'Class' => 'class',
                'File' => 'file',
                'Line' => 'line',
                'Count' => ['occurrences', 'count'],
                'Last Seen' => 'last_occurred_at',
            ],
            'routes' => [
                '' => '{team}/errors',
                'id' => '{team}/errors/{id}',
                'environment' => '{team}/environments/{environment}/errors',
            ],
        ],

        'finances' => [
            'description' => 'Show finance overview for a team',
            'ability' => 'finances:read',
            'columns' => [],
            'routes' => [
                '' => ['path' => '{team}/finances', 'envelope' => 'custom'],
            ],
        ],

        'incidents' => [
            'description' => 'List incidents in a team or for a domain',
            'ability' => 'incidents:read',
            'columns' => [
                'ID' => 'id',
                'Status' => 'status_label',
                'Severity' => 'severity_label',
                'Resolved' => 'resolved_at',
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/incidents',
                'id' => '{team}/incidents/{id}',
                'domain' => '{team}/domains/{domain}/incidents',
            ],
        ],

        'issues' => [
            'description' => 'List repository issues in a team',
            'ability' => 'issues:read',
            'columns' => [
                '#' => 'number',
                'Title' => ['title', 'limit', 50],
                'Status' => 'status',
                'Comments' => ['comments_count', 'count'],
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/issues',
                'id' => '{team}/issues/{id}',
            ],
        ],

        'projects' => [
            'description' => 'List projects in a team',
            'ability' => 'projects:read',
            'columns' => [
                'Name' => 'name',
                'Slug' => 'slug',
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/projects',
                'id' => '{team}/projects/{id}',
            ],
        ],

        'redirects' => [
            'description' => 'List redirects for an environment',
            'ability' => 'redirects:read',
            'columns' => [
                'Source' => 'source',
                'Destination' => ['destination', 'limit', 40],
                'Code' => 'code',
                'Created' => 'created_at',
            ],
            'routes' => [
                'environment' => '{team}/environments/{environment}/redirects',
            ],
        ],

        'repositories' => [
            'description' => 'List repositories in a team or on a server',
            'ability' => 'repositories:read',
            'columns' => [
                'Name' => 'name',
                'Path' => 'path',
                'Language' => 'language',
                'Private' => ['is_private', 'bool'],
            ],
            'routes' => [
                '' => '{team}/repositories',
                'server' => '{team}/servers/{server}/repositories',
            ],
        ],

        'schedulers' => [
            'description' => 'List schedulers in a team, on a server, or in an environment',
            'ability' => 'schedulers:read',
            'columns' => [
                'Name' => 'name',
                'Command' => 'command',
                'Frequency' => 'frequency_name',
                'Last Heartbeat' => 'heartbeated_at',
            ],
            'routes' => [
                '' => '{team}/schedulers',
                'server' => '{team}/servers/{server}/cron',
                'environment' => '{team}/environments/{environment}/cron',
            ],
        ],

        'servers' => [
            'description' => 'List servers in a team or in an environment',
            'ability' => 'servers:read',
            'columns' => [
                'Name' => 'name',
                'IP' => 'ip',
                'Provider' => 'provider_slug',
                'Type' => 'server_type',
                'Status' => 'status',
                'Price' => ['price', 'money'],
            ],
            'routes' => [
                '' => '{team}/servers',
                'id' => '{team}/servers/{id}',
                'environment' => '{team}/environments/{environment}/servers',
            ],
        ],

        'services' => [
            'description' => 'List services installed on a server',
            'ability' => 'services:read',
            'columns' => [],
            'routes' => [
                'server' => ['path' => '{team}/servers/{server}/services', 'envelope' => 'custom'],
            ],
        ],

        'sites' => [
            'description' => 'List sites in a team or on a server',
            'ability' => 'sites:read',
            'columns' => [
                'Name' => 'name',
                'Environments' => ['environments_count', 'count'],
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/sites',
                'id' => '{team}/sites/{id}',
                'server' => '{team}/servers/{server}/environments',
            ],
        ],

        'storages' => [
            'description' => 'List storages in a team or in an environment',
            'ability' => 'storages:read',
            'columns' => [
                'Name' => 'name',
                'Provider' => 'provider_slug',
                'Size' => 'size',
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/storages',
                'environment' => '{team}/environments/{environment}/storages',
            ],
        ],

        'tasks' => [
            'description' => 'List server tasks in a team',
            'ability' => 'tasks:read',
            'columns' => [
                'Name' => 'name',
                'Status' => 'status',
                'Deploys' => ['deploys', 'bool'],
                'Created' => 'created_at',
            ],
            'routes' => [
                '' => '{team}/tasks',
                'id' => '{team}/tasks/{id}',
            ],
        ],

        'vulnerabilities' => [
            'description' => 'List security vulnerabilities in a team',
            'ability' => 'security:read',
            'columns' => [
                'Package' => 'package_name',
                'Identifier' => 'identifier',
                'Severity' => 'severity',
                'CVSS' => 'cvss',
                'Ecosystem' => 'ecosystem',
            ],
            'routes' => [
                '' => '{team}/security/vulnerabilities',
                'id' => '{team}/security/vulnerabilities/{id}',
            ],
        ],

    ],

    /*
    | GET endpoints reachable only through `rocket api:get <path>` — helper reads
    | and one-off shapes that don't warrant a dedicated command. Listed so
    | `api:check` treats them as covered rather than missing.
    */
    'raw_only' => [
        '{team}/servers/{server}/access',
        '{team}/servers/{server}/stats',
        '{team}/servers/{server}/status',
        '{team}/servers/create-using-services/{serverType}',
        '{team}/environments/{environment}/server-options',
        '{team}/security/repositories/{repository}',
        '{team}/domains/{domain}/dns/export',
    ],

];
