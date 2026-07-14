<?php

/**
 * Config mínima do (ex-)Spatie laravel-permission. O app NÃO usa mais Spatie
 * (permissões via App\Services\PermissionService), mas a migration relíquia
 * `create_permission_tables` ainda roda no RefreshDatabase e lê estas chaves.
 * Sem este arquivo, qualquer teste com RefreshDatabase quebra na migration.
 */
return [
    'models' => [
        'permission' => \App\Models\User::class, // placeholder (não usado)
        'role'       => \App\Models\User::class,
    ],
    'table_names' => [
        'roles'                 => 'roles',
        'permissions'           => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ],
    'column_names' => [
        'role_pivot_key'       => null,
        'permission_pivot_key' => null,
        'model_morph_key'      => 'model_id',
        'team_foreign_key'     => 'team_id',
    ],
    'teams' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception'       => false,
    'enable_wildcard_permission'      => false,
    'cache' => [
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),
        'key'             => 'spatie.permission.cache',
        'store'           => 'default',
    ],
];
