@include('partials.tabs', ['label' => 'Expense sections', 'class' => 'mb-5', 'tabs' => array_values(array_filter([
    ['Expenses & bills', $bu.'/expenses', $active === 'expenses'],
    ['Mileage', $bu.'/mileage', $active === 'mileage'],
    ['Projects', $bu.'/projects', $active === 'projects'],
    $ctx->can('expenses.manage') ? ['Import', $bu.'/expenses/import', $active === 'import'] : null,
    ['Vendors', $bu.'/vendors', $active === 'vendors'],
]))])
