<?php

use App\Admin\Support\AdminTheme;
use App\Admin\Support\OperationsAccess;
use App\Admin\Support\WorkbenchAssets;
use Dcat\Admin\Admin;

$bodyClasses = config('admin.layout.body_class', []);
$bodyClasses = is_array($bodyClasses) ? $bodyClasses : preg_split('/\s+/', (string) $bodyClasses, -1, PREG_SPLIT_NO_EMPTY);
config(['admin.layout.body_class' => array_values(array_unique(array_merge($bodyClasses, ['dujiaoka-admin'])))]);
AdminTheme::register();
WorkbenchAssets::register();

Admin::menu(function ($menu) {
    $user = Admin::user();
    if (!$user) return;
    $roles = (method_exists($user, 'allRoles') ? $user->allRoles() : $user->roles)
        ->map(function ($role) { return ['slug' => $role->slug]; })->all();
    $permissions = $user->allPermissions()->map(function ($permission) { return ['slug' => $permission->slug]; })->all();
    $menuNodes = [];
    $icons = ['overview' => 'fa-line-chart', 'stripe' => 'fa-credit-card', 'paypal' => 'fa-paypal',
        'notifications' => 'fa-bell-o', 'quotes' => 'fa-exchange', 'runtime' => 'fa-heartbeat'];
    foreach (OperationsAccess::sections() as $section => $label) {
        if (!OperationsAccess::allows($section, 'GET')) continue;
        // String IDs do not collide with the existing numeric database menu IDs.
        $menuNodes[] = ['id' => 'dujiaoka-operations-' . $section, 'parent_id' => 0,
            'title' => $label, 'icon' => $icons[$section], 'uri' => 'operations/' . $section,
            'roles' => $roles, 'permissions' => $permissions];
    }
    if ($menuNodes) $menu->add($menuNodes, 15);
});
