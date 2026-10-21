<?php

/**
 * The top menu, in order. A link is shown only to people who may open its page: the roles are read from the
 * route's own `role:` middleware, so the menu can never disagree with the permissions.
 *
 *   route  the route name (a route that does not exist, or has parameters, is skipped)
 *   label  the text; it is translated when a translation exists
 *   group  optional dropdown name. Without it: admin.* pages go under "Admin", manager.* pages under "Reports",
 *          and everything else is a plain link. A dropdown with one link is shown as that link.
 */
return [
    'items' => [
        ['route' => 'super.schools', 'label' => 'Schools'],
        ['route' => 'till', 'label' => 'Till'],
        ['route' => 'till.meals', 'label' => 'Meal screen'],
        ['route' => 'till.sales', 'label' => 'Sales'],
        ['route' => 'manager', 'label' => 'Manager'],
        ['route' => 'admin.students', 'label' => 'Students'],
        ['route' => 'admin.parents', 'label' => 'Parents'],
        ['route' => 'admin.consent', 'label' => 'Consent'],
        ['route' => 'admin.staff', 'label' => 'Staff'],
        ['route' => 'admin.meals', 'label' => 'Meals'],
        ['route' => 'admin.products', 'label' => 'Products'],
        ['route' => 'admin.categories', 'label' => 'Categories'],
        ['route' => 'admin.topups', 'label' => 'Top-ups'],
        ['route' => 'admin.settings', 'label' => 'Settings'],
        ['route' => 'admin.audit', 'label' => 'Audit log'],
        ['route' => 'manager.reports.daily', 'label' => 'Daily sales'],
        ['route' => 'manager.reports.meals', 'label' => 'Meals report'],
        ['route' => 'manager.reports.topups', 'label' => 'Top-ups report'],
        ['route' => 'manager.reports.balances', 'label' => 'Balances'],
        ['route' => 'manager.reports.reconciliation', 'label' => 'Reconciliation'],
        ['route' => 'manager.refunds', 'label' => 'Refunds'],
    ],
];
