<?php

/*
|--------------------------------------------------------------------------
| Rocketeers API endpoint registry
|--------------------------------------------------------------------------
|
| Every endpoint the CLI can reach. Each resource maps to one discoverable
| `{key}` command. Routes are keyed by their "scope signature": the sorted,
| pipe-joined set of non-{team} path placeholders provided as flags.
|
|   ''             team-level list           rocket daemons --team=x
|   'id'           single resource show      rocket sites --team=x --id=42
|   'server'       scoped to a server        rocket daemons --team=x --server=uuid
|   'environment'  scoped to an environment  rocket daemons --team=x --environment=id
|   'domain'       scoped to a domain        rocket dns --team=x --domain=example.com
|   'environment|id'  nested show            rocket deployments --team=x --environment=id --id=dep
|
| GET route value: a path string (envelope inferred — `single` when the signature
| contains `id`, otherwise `paginated`) OR ['path' => ..., 'envelope' => 'single'|'custom'].
|
| Column value: 'field' (plain, dot-notation allowed) OR ['field', 'format', ...args]
| where format is one of: money, bool, limit (arg: length), count.
|
| `writes` — actions invoked via --create/--update/--delete/--action=<name>. Each:
|   'method'  => POST|PUT|PATCH|DELETE
|   'ability' => required token ability
|   'routes'  => scope signature => path template (reused by resolveRoute)
|   'fields'  => name => ['required' => bool, 'type' => 'int'|'bool'] (prompted if absent)
|   'inject'  => body key => context ('team' fills the resolved team slug)
|   'confirm' => true for destructive ops (prompts unless --force)
|
| Endpoints reachable only through the raw verb commands (get/post/put/patch/delete)
| are listed under `raw_only` so `check` classifies them instead of flagging missing.
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'apps:create', 'routes' => ['' => '{team}/apps'], 'inject' => ['team_id' => 'team'], 'fields' => ['name' => ['required' => true], 'project_id' => ['from' => 'projects'], 'manager_id' => ['required' => true]]],
                'update' => ['method' => 'PUT', 'ability' => 'apps:update', 'routes' => ['id' => '{team}/apps/{id}'], 'fields' => ['name' => ['required' => true], 'project_id' => []]],
                'delete' => ['method' => 'DELETE', 'ability' => 'apps:delete', 'routes' => ['id' => '{team}/apps/{id}'], 'confirm' => true],
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
            'writes' => [
                'run' => ['method' => 'POST', 'ability' => 'backups:run', 'routes' => ['server' => '{team}/servers/{server}/backups/run']],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'certificates:create', 'routes' => ['' => '{team}/certificates'], 'fields' => ['name' => ['required' => true], 'private_key' => ['required' => true], 'certificate' => ['required' => true]]],
                'update' => ['method' => 'PUT', 'ability' => 'certificates:update', 'routes' => ['id' => '{team}/certificates/{id}'], 'fields' => ['name' => [], 'private_key' => [], 'certificate' => []]],
                'delete' => ['method' => 'DELETE', 'ability' => 'certificates:delete', 'routes' => ['id' => '{team}/certificates/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'clients:create', 'routes' => ['' => '{team}/clients'], 'fields' => ['name' => ['required' => true]]],
                'update' => ['method' => 'PUT', 'ability' => 'clients:update', 'routes' => ['id' => '{team}/clients/{id}'], 'fields' => ['name' => ['required' => true]]],
                'delete' => ['method' => 'DELETE', 'ability' => 'clients:delete', 'routes' => ['id' => '{team}/clients/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'daemons:create', 'routes' => ['' => '{team}/daemons', 'server' => '{team}/servers/{server}/daemons', 'environment' => '{team}/environments/{environment}/daemons'], 'fields' => ['name' => ['required' => true], 'command' => ['required' => true], 'username' => [], 'directory' => [], 'processes' => ['type' => 'int'], 'environment_id' => ['from' => 'environments'], 'server_id' => ['from' => 'servers']]],
                'update' => ['method' => 'PUT', 'ability' => 'daemons:update', 'routes' => ['id' => '{team}/daemons/{id}', 'id|server' => '{team}/servers/{server}/daemons/{id}', 'environment|id' => '{team}/environments/{environment}/daemons/{id}'], 'fields' => ['name' => [], 'command' => [], 'username' => [], 'directory' => [], 'processes' => ['type' => 'int']]],
                'delete' => ['method' => 'DELETE', 'ability' => 'daemons:delete', 'routes' => ['id' => '{team}/daemons/{id}', 'id|server' => '{team}/servers/{server}/daemons/{id}', 'environment|id' => '{team}/environments/{environment}/daemons/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'databases:create', 'routes' => ['' => '{team}/databases', 'server' => '{team}/servers/{server}/databases', 'environment' => '{team}/environments/{environment}/databases'], 'fields' => ['name' => []]],
                'delete' => ['method' => 'DELETE', 'ability' => 'databases:delete', 'routes' => ['id' => '{team}/databases/{id}', 'id|server' => '{team}/servers/{server}/databases/{id}', 'environment|id' => '{team}/environments/{environment}/databases/{id}'], 'confirm' => true],
                'attach' => ['method' => 'POST', 'ability' => 'databases:update', 'routes' => ['environment|id' => '{team}/environments/{environment}/databases/{id}/attach']],
                'detach' => ['method' => 'DELETE', 'ability' => 'databases:update', 'routes' => ['environment|id' => '{team}/environments/{environment}/databases/{id}/detach'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'deployments:create', 'routes' => ['environment' => '{team}/environments/{environment}/deploy'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'domains:create', 'routes' => ['' => '{team}/domains'], 'fields' => ['name' => ['required' => true], 'alias' => [], 'environment' => [], 'provider_account_id' => []]],
                'sync' => ['method' => 'POST', 'ability' => 'domains:sync', 'routes' => ['' => '{team}/domains/sync']],
                'response-time' => ['method' => 'POST', 'ability' => 'domains:read', 'routes' => ['' => '{team}/domains/response-time'], 'fields' => ['ids' => ['required' => true]]],
            ],
        ],

        'dns' => [
            'description' => 'List DNS records for a domain',
            'ability' => 'dns:read',
            'columns' => [],
            'routes' => [
                'domain' => ['path' => '{team}/domains/{domain}/dns', 'envelope' => 'custom'],
            ],
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'dns:manage', 'routes' => ['domain' => '{team}/domains/{domain}/dns/records'], 'fields' => ['type' => ['required' => true], 'name' => ['required' => true], 'content' => ['required' => true], 'ttl' => ['type' => 'int'], 'priority' => ['type' => 'int'], 'proxied' => ['type' => 'bool']]],
                'update' => ['method' => 'PUT', 'ability' => 'dns:manage', 'routes' => ['domain|id' => '{team}/domains/{domain}/dns/records/{id}'], 'fields' => ['type' => [], 'name' => [], 'content' => [], 'ttl' => ['type' => 'int'], 'priority' => ['type' => 'int'], 'proxied' => ['type' => 'bool']]],
                'delete' => ['method' => 'DELETE', 'ability' => 'dns:manage', 'routes' => ['domain|id' => '{team}/domains/{domain}/dns/records/{id}'], 'confirm' => true],
                'clear' => ['method' => 'DELETE', 'ability' => 'dns:manage', 'routes' => ['domain' => '{team}/domains/{domain}/dns/records'], 'confirm' => true],
                'template' => ['method' => 'POST', 'ability' => 'dns:manage', 'routes' => ['domain' => '{team}/domains/{domain}/dns/template'], 'fields' => ['records' => ['required' => true]]],
            ],
        ],

        'environments' => [
            'description' => 'List environments in a team',
            'ability' => 'environments:read',
            'columns' => [
                'Name' => 'name',
                'Stage' => 'environment',
                'Type' => 'type',
                'Project' => 'project.name',
                'Top domain' => 'domain',
                'PHP' => 'php_version',
                'Auto Deploy' => ['auto_deploy', 'bool'],
            ],
            'routes' => [
                '' => '{team}/environments',
                'id' => '{team}/environments/{id}',
            ],
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'environments:create', 'routes' => ['' => '{team}/environments'], 'fields' => [
                    'name' => ['required' => true],
                    'servers' => ['required' => true, 'from' => 'servers', 'multiple' => true],
                    'web_server_id' => ['required' => true, 'from' => 'servers', 'only_selected' => 'servers'],
                    'repository' => ['required' => true, 'hint' => 'format: providerAccountId:repositoryProviderId'],
                    'branch' => ['required' => true, 'hint' => 'e.g. main'],
                    'environment' => ['required' => true, 'hint' => 'stage, e.g. production'],
                    'type' => ['required' => true, 'hint' => 'e.g. laravel'],
                    'deployment_task_id' => ['required' => true, 'from' => 'tasks'],
                    'php_version' => ['required' => true, 'hint' => 'e.g. 8.4'],
                    'project' => ['required' => true, 'from' => 'projects'],
                    'owner_type' => ['required' => true, 'options' => ['site', 'app']],
                    'owner_id' => ['required' => true, 'from_field' => 'owner_type'],
                    'database_type' => ['options' => ['mysql', 'postgresql']],
                ]],
                'update' => ['method' => 'PUT', 'ability' => 'environments:update', 'routes' => ['id' => '{team}/environments/{id}'], 'fields' => ['manager_id' => [], 'branch' => [], 'php_version' => [], 'php_memory_limit' => [], 'php_max_upload_size' => [], 'octane' => ['type' => 'bool']]],
                'delete' => ['method' => 'DELETE', 'ability' => 'environments:delete', 'routes' => ['id' => '{team}/environments/{id}'], 'confirm' => true],
                'auto-deploy' => ['method' => 'PATCH', 'ability' => 'environments:update', 'routes' => ['id' => '{team}/environments/{id}/auto-deploy'], 'fields' => ['auto_deploy' => ['type' => 'bool', 'required' => true]]],
                'sla-status' => ['method' => 'PATCH', 'ability' => 'environments:update', 'routes' => ['id' => '{team}/environments/{id}/sla-status'], 'fields' => ['sla_status' => ['required' => true]]],
                'attach-server' => ['method' => 'POST', 'ability' => 'servers:update', 'routes' => ['id|server' => '{team}/environments/{id}/servers/{server}/attach']],
                'detach-server' => ['method' => 'DELETE', 'ability' => 'servers:update', 'routes' => ['id|server' => '{team}/environments/{id}/servers/{server}/detach'], 'confirm' => true],
                'set-web' => ['method' => 'PATCH', 'ability' => 'servers:update', 'routes' => ['id|server' => '{team}/environments/{id}/servers/{server}/web']],
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
            'writes' => [
                'ignore' => ['method' => 'PATCH', 'ability' => 'errors:update', 'routes' => ['id' => '{team}/errors/{id}/ignore']],
                'delete' => ['method' => 'DELETE', 'ability' => 'errors:delete', 'routes' => ['id' => '{team}/errors/{id}/occurrences'], 'confirm' => true],
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
            'writes' => [
                'acknowledge' => ['method' => 'PATCH', 'ability' => 'incidents:manage', 'routes' => ['id' => '{team}/incidents/{id}/acknowledge']],
                'investigate' => ['method' => 'PATCH', 'ability' => 'incidents:manage', 'routes' => ['id' => '{team}/incidents/{id}/investigate']],
                'monitor' => ['method' => 'PATCH', 'ability' => 'incidents:manage', 'routes' => ['id' => '{team}/incidents/{id}/monitor']],
                'resolve' => ['method' => 'PATCH', 'ability' => 'incidents:manage', 'routes' => ['id' => '{team}/incidents/{id}/resolve'], 'fields' => ['root_cause' => [], 'resolution_summary' => []]],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'issues:create', 'routes' => ['' => '{team}/issues'], 'fields' => ['repository_id' => ['required' => true, 'from' => 'repositories'], 'title' => ['required' => true], 'body' => []]],
                'update' => ['method' => 'PUT', 'ability' => 'issues:update', 'routes' => ['id' => '{team}/issues/{id}'], 'fields' => ['title' => [], 'body' => [], 'status' => []]],
                'labels' => ['method' => 'PATCH', 'ability' => 'issues:update', 'routes' => ['id' => '{team}/issues/{id}/labels'], 'fields' => ['labels' => ['required' => true]]],
                'toggle-status' => ['method' => 'PATCH', 'ability' => 'issues:update', 'routes' => ['id' => '{team}/issues/{id}/toggle-status']],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'projects:create', 'routes' => ['' => '{team}/projects'], 'inject' => ['team_id' => 'team'], 'fields' => ['name' => ['required' => true], 'client_id' => ['from' => 'clients'], 'manager_id' => [], 'user_id' => []]],
                'update' => ['method' => 'PUT', 'ability' => 'projects:update', 'routes' => ['id' => '{team}/projects/{id}'], 'fields' => ['name' => ['required' => true], 'client_id' => []]],
                'delete' => ['method' => 'DELETE', 'ability' => 'projects:delete', 'routes' => ['id' => '{team}/projects/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'redirects:create', 'routes' => ['environment' => '{team}/environments/{environment}/redirects'], 'fields' => ['source' => ['required' => true], 'destination' => ['required' => true], 'code' => ['type' => 'int']]],
                'update' => ['method' => 'PUT', 'ability' => 'redirects:update', 'routes' => ['environment|id' => '{team}/environments/{environment}/redirects/{id}'], 'fields' => ['source' => [], 'destination' => [], 'code' => ['type' => 'int']]],
                'delete' => ['method' => 'DELETE', 'ability' => 'redirects:delete', 'routes' => ['environment|id' => '{team}/environments/{environment}/redirects/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'schedulers:create', 'routes' => ['' => '{team}/schedulers', 'server' => '{team}/servers/{server}/cron', 'environment' => '{team}/environments/{environment}/cron'], 'fields' => ['name' => ['required' => true], 'command' => ['required' => true], 'frequency' => ['required' => true], 'username' => [], 'directory' => [], 'environment_id' => ['from' => 'environments'], 'server_id' => ['from' => 'servers']]],
                'update' => ['method' => 'PUT', 'ability' => 'schedulers:update', 'routes' => ['id' => '{team}/schedulers/{id}', 'id|server' => '{team}/servers/{server}/cron/{id}', 'environment|id' => '{team}/environments/{environment}/cron/{id}'], 'fields' => ['name' => [], 'command' => [], 'frequency' => [], 'username' => [], 'directory' => []]],
                'delete' => ['method' => 'DELETE', 'ability' => 'schedulers:delete', 'routes' => ['id' => '{team}/schedulers/{id}', 'id|server' => '{team}/servers/{server}/cron/{id}', 'environment|id' => '{team}/environments/{environment}/cron/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'servers:create', 'routes' => ['' => '{team}/servers'], 'fields' => ['name' => ['required' => true], 'provider_account_id' => ['required' => true], 'server_type' => [], 'region' => [], 'size' => [], 'backups_enabled' => ['type' => 'bool'], 'ipv6_enabled' => ['type' => 'bool'], 'private_network' => ['type' => 'bool']]],
                'sync' => ['method' => 'POST', 'ability' => 'servers:sync', 'routes' => ['' => '{team}/servers/sync']],
                'reboot' => ['method' => 'POST', 'ability' => 'servers:reboot', 'routes' => ['id' => '{team}/servers/{id}/reboot'], 'confirm' => true],
                'rename' => ['method' => 'PUT', 'ability' => 'servers:update', 'routes' => ['id' => '{team}/servers/{id}/name'], 'fields' => ['name' => ['required' => true]]],
                'access' => ['method' => 'PUT', 'ability' => 'servers:update', 'routes' => ['id' => '{team}/servers/{id}/access'], 'fields' => ['allowed_ips' => ['required' => true]]],
                'blacklist' => ['method' => 'PUT', 'ability' => 'servers:update', 'routes' => ['id' => '{team}/servers/{id}/access/blacklist'], 'fields' => ['blacklisted_ips' => ['required' => true]]],
                'check-availability' => ['method' => 'POST', 'ability' => 'servers:create', 'routes' => ['' => '{team}/servers/check-availability'], 'fields' => ['provider_account_id' => ['required' => true], 'size' => [], 'region' => []]],
                'check-username' => ['method' => 'POST', 'ability' => 'servers:read', 'routes' => ['id' => '{team}/servers/{id}/check-username'], 'fields' => ['username' => ['required' => true]]],
                'install-service' => ['method' => 'POST', 'ability' => 'services:manage', 'routes' => ['id' => '{team}/servers/{id}/services'], 'fields' => ['service' => ['required' => true], 'version' => []]],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'sites:create', 'routes' => ['' => '{team}/sites'], 'inject' => ['team_id' => 'team'], 'fields' => ['name' => ['required' => true], 'project_id' => ['from' => 'projects'], 'manager_id' => ['required' => true]]],
                'update' => ['method' => 'PUT', 'ability' => 'sites:update', 'routes' => ['id' => '{team}/sites/{id}'], 'fields' => ['name' => ['required' => true], 'project_id' => []]],
                'delete' => ['method' => 'DELETE', 'ability' => 'sites:delete', 'routes' => ['id' => '{team}/sites/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'storages:create', 'routes' => ['environment' => '{team}/environments/{environment}/storages'], 'fields' => ['name' => ['required' => true], 'provider_account_id' => []]],
                'attach' => ['method' => 'POST', 'ability' => 'storages:update', 'routes' => ['environment' => '{team}/environments/{environment}/storages/attach'], 'fields' => ['storage_id' => ['required' => true]]],
                'sync' => ['method' => 'POST', 'ability' => 'storages:sync', 'routes' => ['environment' => '{team}/environments/{environment}/storages/sync']],
                'delete' => ['method' => 'DELETE', 'ability' => 'storages:delete', 'routes' => ['environment|id' => '{team}/environments/{environment}/storages/{id}'], 'confirm' => true],
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
            'writes' => [
                'create' => ['method' => 'POST', 'ability' => 'tasks:create', 'routes' => ['' => '{team}/tasks'], 'fields' => ['name' => ['required' => true], 'commands' => ['required' => true], 'deploys' => ['type' => 'bool']]],
                'update' => ['method' => 'PUT', 'ability' => 'tasks:update', 'routes' => ['id' => '{team}/tasks/{id}'], 'fields' => ['name' => [], 'commands' => [], 'deploys' => ['type' => 'bool']]],
                'delete' => ['method' => 'DELETE', 'ability' => 'tasks:delete', 'routes' => ['id' => '{team}/tasks/{id}'], 'confirm' => true],
                'run' => ['method' => 'POST', 'ability' => 'tasks:run', 'routes' => ['id' => '{team}/tasks/{id}/run'], 'fields' => ['type' => [], 'target_id' => []]],
                'schedule' => ['method' => 'POST', 'ability' => 'tasks:schedule', 'routes' => ['id' => '{team}/tasks/{id}/schedule'], 'fields' => ['target_type' => [], 'target_id' => [], 'expression' => ['required' => true], 'is_active' => ['type' => 'bool']]],
                'unschedule' => ['method' => 'DELETE', 'ability' => 'tasks:schedule', 'routes' => ['id' => '{team}/tasks/{id}/schedule'], 'confirm' => true],
                'toggle-schedule' => ['method' => 'PUT', 'ability' => 'tasks:schedule', 'routes' => ['id' => '{team}/tasks/{id}/schedule/toggle']],
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
    | Endpoints reachable only through the raw verb commands (get/post/put/patch/
    | delete) — helper reads and one-off shapes that don't warrant a dedicated
    | command. Listed so `check` treats them as covered rather than missing.
    */
    'raw_only' => [
        '{team}/servers/{server}/access',
        '{team}/servers/{server}/stats',
        '{team}/servers/{server}/status',
        '{team}/servers/create-using-services/{serverType}',
        '{team}/servers/{server}/services/{service}/{action}',
        '{team}/environments/{environment}/server-options',
        '{team}/security/repositories/{repository}',
        '{team}/domains/{domain}/dns/export',
    ],

];
