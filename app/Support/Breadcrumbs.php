<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReconciliationSession;
use App\Models\RecurringSchedule;
use App\Services\Reports\ReportService;

/** Builds breadcrumb trails for business pages from the URL, resolving record ids to names (tenant-scoped). */
final class Breadcrumbs
{
    /** @return list<array{0:string,1:?string}> [label, url] — the last item is the current page */
    public static function for(Business $b, string $path): array
    {
        $bu = '/b/'.$b->ulid;
        $seg = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        $seg = array_slice($seg, 2); // drop "b/{ulid}"
        if (! $seg) return [];

        $trail = [['Overview', $bu]];
        $add = function (string $label, ?string $url = null) use (&$trail) { $trail[] = [$label, $url]; };
        [$s0, $s1, $s2] = $seg + [null, null, null];
        $id = is_numeric($s1) ? (int) $s1 : null;
        $find = fn (string $model) => $id ? $model::where('business_id', $b->id)->find($id) : null;
        $action = fn (?string $s) => match ($s) {
            'edit' => 'Edit', 'send' => 'Send', 'credit' => 'Credit note', 'statement' => 'Statement',
            'countersign' => 'Countersign', 'import' => 'Import preview', default => null,
        };
        // Section root + optional record + optional action.
        $section = function (string $label, string $root, ?string $model = null, ?\Closure $name = null, string $newLabel = 'New') use ($s1, $s2, $id, $find, $add, $action, $bu) {
            $add($label, $bu.$root);
            if ($s1 === 'new') { $add($newLabel); return; }
            if ($id && $model) {
                $rec = $find($model);
                $add($rec ? $name($rec) : '#'.$id, $bu.$root.'/'.$id);
                if ($s2 && ($a = $action($s2))) $add($a);
            }
        };

        match ($s0) {
            'clients' => $section('Clients', '/clients', Client::class, fn ($c) => $c->display_name, 'New client'),
            'invoices' => $section('Invoices', '/invoices', Invoice::class, fn ($i) => 'Invoice '.$i->displayNumber(), 'New invoice'),
            'payments' => $section('Payments', '/payments', Payment::class, fn ($p) => 'Payment · '.$p->received_date?->format('M j, Y'), 'Receive payment'),
            'claims' => [$add('Payments', $bu.'/payments'), $add('Payment report')],
            'expenses' => $section('Expenses', '/expenses', Expense::class, fn ($e) => ($e->kind === 'bill' ? 'Bill' : 'Expense').' · '.$e->expense_date?->format('M j, Y'), request('kind') === 'bill' ? 'New bill' : 'New expense'),
            'banking' => $section('Banking', '/banking', BankAccount::class, fn ($a) => $a->name),
            'reconciliations' => (function () use ($b, $id, $add, $bu) {
                $add('Banking', $bu.'/banking');
                $r = $id ? ReconciliationSession::where('business_id', $b->id)->find($id) : null;
                if ($r && ($acct = BankAccount::where('business_id', $b->id)->find($r->bank_account_id))) $add($acct->name, $bu.'/banking/'.$acct->id);
                $add('Reconciliation');
            })(),
            'movements' => [$add('Banking', $bu.'/banking'), $add('Transfer / owner activity')],
            'accounts' => $add('Chart of accounts'),
            'journals' => $s1 === 'new' || $id
                ? [$add('Journal entries', $bu.'/journals'), $add($s1 === 'new' ? 'New journal entry' : 'Entry #'.$id)]
                : $add('Journal entries'),
            'periods' => $add('Period close'),
            'reports' => $s1
                ? [$add('Reports', $bu.'/reports'), $add(ReportService::REPORTS[$s1][0] ?? ucwords(str_replace('-', ' ', $s1)))]
                : $add('Reports'),
            'contracts' => $section('Contracts', '/contracts', Contract::class, fn ($c) => $c->number.' · '.$c->title, 'New agreement'),
            'contract-templates' => [$add('Contracts', $bu.'/contracts'), $add('Templates')],
            'recurring' => $section('Recurring billing', '/recurring', RecurringSchedule::class, fn ($s) => $s->name, 'New schedule'),
            'catalog' => $add('Products & services'),
            'vendors' => $add('Vendors'),
            'settings' => $s1
                ? [$add('Settings', $bu.'/settings'), $add(['members' => 'Members', 'roles' => 'Roles & permissions'][$s1] ?? ucfirst($s1))]
                : $add('Settings'),
            'automation' => [$add('Settings', $bu.'/settings'), $add('Automation')],
            'audit' => [$add('Settings', $bu.'/settings'), $add('Audit log')],
            'migration' => $s1
                ? [$add('Data import', $bu.'/migration'), $add('Preview')]
                : $add('Data import'),
            'search' => $add('Search'),
            default => $add(ucwords(str_replace('-', ' ', (string) $s0))),
        };

        // The last crumb is the current page and is never a link.
        $last = count($trail) - 1;
        $trail[$last][1] = null;

        return $trail;
    }
}
