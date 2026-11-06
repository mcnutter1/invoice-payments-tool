@php
    $is = fn (string ...$p) => request()->is(...array_map(fn ($x) => 'b/*/'.$x, $p));
    $settingsTabs = array_values(array_filter([
        $ctx->can('business.view') ? ['General', $bu.'/settings', $is('settings')] : null,
        $ctx->can('members.manage') ? ['Members', $bu.'/settings/members', $is('settings/members')] : null,
        $ctx->can('roles.manage') ? ['Roles', $bu.'/settings/roles', $is('settings/roles')] : null,
        $ctx->can('automation.view') ? ['Automation', $bu.'/automation', $is('automation')] : null,
        $ctx->can('audit.view') ? ['Audit log', $bu.'/audit', $is('audit')] : null,
        $ctx->can('business.manage') ? ['Backups', $bu.'/backups', $is('backups', 'backups/*')] : null,
        $ctx->can('journals.manage') && $ctx->can('clients.manage') ? ['Data import', $bu.'/migration', $is('migration', 'migration/*')] : null,
        $ctx->canAny('expenses.view', 'catalog.manage') ? ['Vendors', $bu.'/vendors', $is('vendors')] : null,
    ]));
@endphp
<h1 class="mb-3">Settings</h1>
@include('partials.tabs', ['tabs' => $settingsTabs, 'label' => 'Settings sections', 'class' => 'mb-5'])
