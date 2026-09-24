<?php

// Permission registry: role => permissions. Server-side enforced via gates.
return [
    'permissions' => [
        'manage_billing',
        'manage_users',
        'manage_roles',
        'manage_settings',
        'view_reports',
        'view_records',
        'create_records',
        'update_records',
        'delete_records',
    ],

    'roles' => [
        'Owner'   => ['*'],
        'Admin'   => ['manage_users', 'manage_roles', 'manage_settings', 'view_reports', 'view_records', 'create_records', 'update_records', 'delete_records'],
        'Manager' => ['view_reports', 'view_records', 'create_records', 'update_records', 'delete_records'],
        'Staff'   => ['view_records', 'create_records', 'update_records'],
        'Viewer'  => ['view_records'],
    ],

    'owner_role' => 'Owner',
];
