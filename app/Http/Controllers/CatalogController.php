<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Vendor;
use App\Services\Audit;
use App\Services\Expenses\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index()
    {
        $b = $this->biz();

        return view('catalog.index', [
            'products' => Product::where('business_id', $b->id)->orderBy('active', 'desc')->orderBy('name')->get(),
            'taxRates' => TaxRate::where('business_id', $b->id)->orderBy('name')->get(),
            'incomeAccounts' => ChartAccount::where('business_id', $b->id)->where('type', 'income')->where('is_active', true)->orderBy('code')->get(),
            'cogsAccounts' => ChartAccount::where('business_id', $b->id)->where('type', 'expense')->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    private function productRules(): array
    {
        $b = $this->biz();

        return [
            'sku' => ['nullable', 'string', 'max:60'],
            'kind' => ['required', Rule::in(['service', 'product'])],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'default_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'income_account_id' => ['required', Rule::exists('chart_accounts', 'id')->where('business_id', $b->id)->where('type', 'income')],
            'cogs_account_id' => ['nullable', Rule::exists('chart_accounts', 'id')->where('business_id', $b->id)->where('type', 'expense')],
            'tax_rate_id' => ['nullable', Rule::exists('tax_rates', 'id')->where('business_id', $b->id)],
            'default_cadence' => ['nullable', Rule::in(['week', 'month', 'quarter', 'year'])],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->productRules());
        $p = new Product($data + ['active' => true]);
        $p->business_id = $this->biz()->id;
        $p->save();
        Audit::log($this->biz(), 'catalog.created', $p);

        return $this->to('/catalog', 'Item added.');
    }

    public function update(Request $request, int $id)
    {
        $p = Product::where('business_id', $this->biz()->id)->findOrFail($id);
        $data = $request->validate($this->productRules());
        $data['active'] = $request->boolean('active');
        $p->update($data);
        Audit::log($this->biz(), 'catalog.updated', $p);

        return $this->to('/catalog', 'Item saved. Existing invoices are unchanged.');
    }

    public function storeTaxRate(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'rate' => ['required', 'numeric', 'min:0', 'max:30']]);
        $t = TaxRate::create($data + ['business_id' => $this->biz()->id, 'active' => true]);
        Audit::log($this->biz(), 'tax_rate.created', $t);

        return $this->to('/catalog', 'Tax rate added.');
    }

    public function updateTaxRate(Request $request, int $id)
    {
        $t = TaxRate::where('business_id', $this->biz()->id)->findOrFail($id);
        $t->update(['active' => $request->boolean('active'), 'name' => $request->validate(['name' => ['required', 'string', 'max:100']])['name']]);
        Audit::log($this->biz(), 'tax_rate.updated', $t);

        return $this->to('/catalog', 'Tax rate saved. Rates on issued invoices never change.');
    }

    public function vendors()
    {
        $b = $this->biz();

        return view('catalog.vendors', [
            'vendors' => Vendor::where('business_id', $b->id)->orderBy('name')->paginate(50),
            'accounts' => ExpenseService::expenseAccounts($b),
        ]);
    }

    private function vendorRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'default_account_id' => ['nullable', Rule::exists('chart_accounts', 'id')->where('business_id', $this->biz()->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function storeVendor(Request $request)
    {
        $v = new Vendor($request->validate($this->vendorRules()));
        $v->business_id = $this->biz()->id;
        $v->save();
        Audit::log($this->biz(), 'vendor.created', $v);

        return back()->with('status', 'Vendor added.');
    }

    public function updateVendor(Request $request, int $id)
    {
        $v = Vendor::where('business_id', $this->biz()->id)->findOrFail($id);
        $v->update($request->validate($this->vendorRules()) + ['status' => $request->boolean('archived') ? 'archived' : 'active']);

        return back()->with('status', 'Vendor saved.');
    }
}
