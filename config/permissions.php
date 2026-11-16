<?php

/*
| Staff roles. Super Admin can do everything (Gate::before). The other roles
| are ready for when you hire staff.
*/

return [
    'abilities' => [
        'manage-settings', 'manage-features', 'manage-products', 'manage-orders',
        'manage-members', 'manage-signals', 'manage-referrals', 'manage-ledger',
        'approve-cashouts', 'approve-payments', 'review-fraud', 'manage-content', 'view-reports', 'view-activity',
    ],

    'roles' => [
        'super_admin' => ['label' => 'Super Admin', 'abilities' => ['*']],
        'manager' => ['label' => 'Manager', 'abilities' => [
            'manage-products', 'manage-orders', 'manage-members', 'manage-signals',
            'manage-content', 'view-reports', 'approve-payments',
        ]],
        // Fulfilment and Support see payments but can't approve transfers
        // (approving gives value: membership, commissions, goods).
        'fulfilment' => ['label' => 'Fulfilment', 'abilities' => ['manage-orders']],
        'support' => ['label' => 'Support', 'abilities' => ['manage-orders', 'manage-members']],
        'finance' => ['label' => 'Finance', 'abilities' => [
            'manage-ledger', 'approve-cashouts', 'approve-payments', 'review-fraud', 'view-reports',
        ]],
    ],

    // Staff (Super Admin included) must set up and use 2FA before reaching any admin screen.
    // On a live shop this is always on; ADMIN_REQUIRE_2FA=false only works on a developer's computer.
    'require_2fa' => env('APP_ENV', 'production') === 'production' ? true : (bool) env('ADMIN_REQUIRE_2FA', true),
];
