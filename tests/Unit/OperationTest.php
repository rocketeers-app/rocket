<?php

use App\Schema\Field;
use App\Schema\SchemaCache;

it('leaves out the relation field the path already fixes', function (): void {
    $schema = app(SchemaCache::class);

    $serverScoped = array_map(fn (Field $field) => $field->name, $schema->find('servers:daemons:create')->inputFields());
    $environmentScoped = array_map(fn (Field $field) => $field->name, $schema->find('environments:daemons:create')->inputFields());

    expect($serverScoped)->not->toContain('server_id')->toContain('environment_id', 'name')
        ->and($environmentScoped)->not->toContain('environment_id')->toContain('server_id', 'name');
});

it('finds the list a path parameter picks from, scoped to its parent first', function (): void {
    $schema = app(SchemaCache::class);

    expect($schema->listFor($schema->find('environments:read'), 'environment')->name)->toBe('api.team.environments.index')
        ->and($schema->listFor($schema->find('environments:daemons:restart'), 'daemon')->name)->toBe('api.team.environments.daemons.index')
        ->and($schema->listFor($schema->find('environments:domains:detach'), 'domain')->name)->toBe('api.team.domains');
});

it('reads a union type as nullable and keeps the relation hint', function (): void {
    $field = Field::fromSchema([
        'name' => 'server_id',
        'type' => 'string|null',
        'relation' => ['item' => 'servers', 'operation' => 'api.team.servers.index', 'value' => 'id'],
    ]);

    expect($field->type)->toBe('string')
        ->and($field->nullable)->toBeTrue()
        ->and($field->preferredOption())->toBe('server')
        ->and($field->label())->toBe('Server');
});

it('treats deletes and the dangerous server actions as destructive', function (): void {
    $schema = app(SchemaCache::class);

    expect($schema->find('servers:reboot')->isDestructive())->toBeTrue()
        ->and($schema->find('servers:delete')->isDestructive())->toBeTrue()
        ->and($schema->find('servers:snapshots:restore')->isDestructive())->toBeTrue()
        ->and($schema->find('environments:deploy')->isDestructive())->toBeFalse();
});
