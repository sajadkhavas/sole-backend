<?php

return [
    'permissions' => [
        'admin.access' => 'Access the administration panel',
        'users.view' => 'View administrators',
        'users.manage' => 'Manage administrators',
        'catalog.view' => 'View catalog administration',
        'catalog.create' => 'Create catalog records',
        'catalog.update' => 'Update catalog records',
        'catalog.publish' => 'Publish products',
        'catalog.archive' => 'Archive catalog records',
        'inventory.view' => 'View inventory',
        'inventory.adjust' => 'Adjust inventory through the ledger',
        'settings.view' => 'View business settings',
        'settings.update' => 'Update business settings',
        'audit.view' => 'View privileged mutation audit evidence',
    ],
    'roles' => [
        'super-admin' => [
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ],
        'catalog-manager' => [
            'name' => 'Catalog Manager',
            'permissions' => [
                'admin.access',
                'catalog.view',
                'catalog.create',
                'catalog.update',
                'catalog.publish',
                'catalog.archive',
                'inventory.view',
                'inventory.adjust',
            ],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'permissions' => ['admin.access', 'catalog.view', 'inventory.view', 'settings.view', 'audit.view'],
        ],
    ],
];
