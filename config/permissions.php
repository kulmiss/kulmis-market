<?php

/*
| Staff roles. Super Admin can do everything (Gate::before). The other roles
| are ready for when you hire staff.
*/

return [
    'abilities' => [
        'manage-settings', 'manage-features', 'manage-products', 'manage-orders',
        'manage-members', 'manage-signals', 'manage-referrals', 'manage-ledger',
        'approve-cashouts', 'review-fraud', 'manage-content', 'view-reports', 'view-activity',
    ],

    'roles' => [
        'super_admin' => ['label' => 'Super Admin', 'abilities' => ['*']],
        'manager' => ['label' => 'Manager', 'abilities' => [
            'manage-products', 'manage-orders', 'manage-members', 'manage-signals',
            'manage-content', 'view-reports',
        ]],
        'fulfilment' => ['label' => 'Fulfilment', 'abilities' => ['manage-orders']],
        'support' => ['label' => 'Support', 'abilities' => ['manage-orders', 'manage-members']],
        'finance' => ['label' => 'Finance', 'abilities' => [
            'manage-ledger', 'approve-cashouts', 'review-fraud', 'view-reports',
        ]],
    ],

    // Admins must set up and use 2FA before reaching any admin screen.
    'require_2fa' => (bool) env('ADMIN_REQUIRE_2FA', true),
];
