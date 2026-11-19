<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\TaxTable;
use App\Services\Audit;
use App\Services\Tax\TaxTables;
use App\Services\Tax\TaxTableSync;
use Illuminate\Http\Request;

/** Application-level tax parameters (federal and state) used by every business's tax estimate. */
class PlatformTaxTableController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless($this->ctx()->user?->isPlatformAdmin(), 403);
    }

    public function index(Request $request, TaxTables $tables)
    {
        $this->authorizeAdmin();
        $year = (int) $request->query('year', now()->year);
        $j = strtoupper((string) $request->query('jurisdiction', 'US'));

        return view('platform.tax-tables', [
            'rows' => TaxTable::orderBy('jurisdiction')->orderByDesc('year')->get(),
            'jurisdictions' => $tables->jurisdictions(),
            'selected' => $tables->resolve($j, $year), 'year' => $year, 'jurisdiction' => $j,
            'lastSync' => PlatformSetting::read('tax_tables.last_sync'),
            'sources' => [TaxTableSync::IRS_RATES_URL, TaxTableSync::SSA_BASE_URL, config('finance.tax.feed_url') ?: null],
        ]);
    }

    public function sync(TaxTableSync $sync)
    {
        $this->authorizeAdmin();
        $r = $sync->sync();

        return back()->with($r['ok'] ? 'status' : 'error', ($r['changes'] ? 'Updated: '.implode(', ', $r['changes']).'.' : 'No changes found.').($r['errors'] ? ' Errors: '.implode('; ', $r['errors']) : ''));
    }

    public function import(Request $request, TaxTables $tables)
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'jurisdiction' => ['required', 'regex:/^[A-Za-z]{2,10}$/'], 'year' => ['required', 'integer', 'min:2018', 'max:2100'],
            'json' => ['required', 'string', 'max:50000'], 'notes' => ['nullable', 'string', 'max:500'], 'source_url' => ['nullable', 'url', 'max:255'],
        ]);
        $payload = json_decode($data['json'], true);
        if (! is_array($payload)) return back()->withInput()->with('error', 'That is not valid JSON.');
        $tables->save($data['jurisdiction'], (int) $data['year'], $payload, 'manual', $data['source_url'] ?? null, $data['notes'] ?? null, $this->ctx()->user?->id);

        return redirect('/platform/tax-tables?jurisdiction='.strtoupper($data['jurisdiction']).'&year='.$data['year'])->with('status', 'Tax table saved.');
    }

    public function destroy(int $id)
    {
        $this->authorizeAdmin();
        $row = TaxTable::findOrFail($id);
        Audit::log(null, 'tax_table.deleted', $row, $row->data, null);
        $row->delete();

        return back()->with('status', 'Override removed; built-in values (if any) apply again.');
    }
}
