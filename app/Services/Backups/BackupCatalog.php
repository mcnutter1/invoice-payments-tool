<?php

namespace App\Services\Backups;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Which tables make up a business, how each is scoped, keyed and change-tracked. */
class BackupCatalog
{
    /** Everything a business owns, parents before children. */
    public const TABLES = [
        'businesses', 'private_documents', 'roles', 'role_permissions', 'memberships', 'membership_roles', 'invitations', 'central_role_mappings',
        'chart_accounts', 'bank_accounts', 'tax_rates', 'clients', 'contacts', 'products_services', 'vendors',
        'accounting_periods', 'journal_entries', 'journal_lines', 'cash_basis_projection_events',
        'contract_templates', 'contracts', 'contract_versions', 'contract_recipients', 'electronic_consents', 'signature_events', 'contract_billing_milestones',
        'invoice_sequences', 'recurring_schedules', 'recurring_line_items', 'invoices', 'invoice_lines', 'invoice_tax_lines', 'invoice_document_versions',
        'credit_notes', 'credit_note_lines', 'payment_claims', 'payments', 'payment_allocations', 'refunds', 'schedule_runs',
        'expenses', 'expense_lines', 'vendor_payments', 'money_movements', 'import_batches', 'reconciliation_sessions', 'bank_transactions',
        'public_tokens', 'email_outbox', 'email_attempts', 'document_access_events', 'audit_events',
    ];

    /** Records people look for in the explorer (others are restored with their parent or are logs). */
    public const ENTITIES = [
        'businesses' => 'Business settings', 'clients' => 'Clients', 'contacts' => 'Contacts', 'products_services' => 'Products & services',
        'vendors' => 'Vendors', 'tax_rates' => 'Tax rates', 'chart_accounts' => 'Accounts', 'bank_accounts' => 'Bank accounts',
        'invoices' => 'Invoices', 'credit_notes' => 'Credit notes', 'payments' => 'Payments', 'payment_claims' => 'Payment reports',
        'payment_allocations' => 'Payment allocations', 'refunds' => 'Refunds', 'expenses' => 'Expenses & bills', 'vendor_payments' => 'Vendor payments',
        'money_movements' => 'Transfers', 'bank_transactions' => 'Bank transactions', 'reconciliation_sessions' => 'Reconciliations',
        'import_batches' => 'Bank imports', 'contracts' => 'Contracts', 'contract_versions' => 'Contract versions',
        'contract_recipients' => 'Contract signers', 'contract_templates' => 'Contract templates', 'contract_billing_milestones' => 'Contract milestones',
        'recurring_schedules' => 'Recurring schedules', 'journal_entries' => 'Journal entries', 'accounting_periods' => 'Accounting periods',
        'roles' => 'Roles', 'memberships' => 'Members', 'invitations' => 'Invitations', 'private_documents' => 'Files',
    ];

    /** Append-only logs: kept as they are when a whole business is rolled back (missing rows are added back). */
    public const PRESERVE_ON_ROLLBACK = ['audit_events', 'document_access_events', 'email_outbox', 'email_attempts', 'signature_events'];

    /** Records that can be restored as a new copy next to the current one. */
    public const COPYABLE = ['clients', 'contacts', 'products_services', 'vendors', 'tax_rates', 'contract_templates', 'invoices', 'recurring_schedules'];

    private array $meta = [];

    public function exists(string $t): bool
    {
        return Schema::hasTable($t);
    }

    /** @return array{columns: list<string>, key: list<string>, ts: ?string, parent: ?array{0:string,1:string}} */
    public function meta(string $t): array
    {
        if (isset($this->meta[$t])) return $this->meta[$t];
        $cols = Schema::getColumnListing($t);
        $key = in_array('id', $cols, true) ? ['id'] : (collect(Schema::getIndexes($t))->firstWhere('primary', true)['columns'] ?? $cols);
        $ts = collect(['updated_at', 'created_at', 'accepted_at', 'started_at'])->first(fn ($c) => in_array($c, $cols, true));
        $parent = null;
        if (! $ts) {
            $fks = collect(Schema::getForeignKeys($t))->map(fn ($fk) => [$fk['foreign_table'], array_values(array_diff($fk['columns'], ['business_id']))])
                ->filter(fn ($fk) => count($fk[1]) === 1 && $fk[0] !== $t && $fk[0] !== 'businesses' && in_array($fk[0], self::TABLES, true));
            // Prefer the conventional owner column, e.g. invoice_lines.invoice_id.
            $fk = $fks->first(fn ($fk) => $fk[1][0] === Str::singular($fk[0]).'_id') ?? $fks->first();
            if ($fk) $parent = [$fk[0], $fk[1][0]];
        }

        return $this->meta[$t] = ['columns' => $cols, 'key' => $key, 'ts' => $ts, 'parent' => $parent];
    }

    /** Child tables restored together with a record (rows without their own history). */
    public function children(string $t): array
    {
        return array_values(array_filter(self::TABLES, fn ($c) => $this->exists($c) && ($this->meta($c)['parent'][0] ?? null) === $t));
    }

    public function query(string $t, int $businessId): Builder
    {
        return match ($t) {
            'businesses' => DB::table($t)->where('id', $businessId),
            'role_permissions' => DB::table($t)->whereIn('role_id', DB::table('roles')->where('business_id', $businessId)->select('id')),
            'email_attempts' => DB::table($t)->whereIn('email_outbox_id', DB::table('email_outbox')->where('business_id', $businessId)->select('id')),
            default => DB::table($t)->where('business_id', $businessId),
        };
    }

    /** Rows changed since a point in time; tables with no history at all are always included. */
    public function changedQuery(string $t, int $businessId, \DateTimeInterface $since): Builder
    {
        $m = $this->meta($t);
        $at = $since->format('Y-m-d H:i:s');
        if ($m['ts']) return $this->query($t, $businessId)->where($m['ts'], '>=', $at);
        if ($m['parent'] && ($pts = $this->meta($m['parent'][0])['ts'])) {
            [$pt, $col] = $m['parent'];

            return $this->query($t, $businessId)->whereIn($col, $this->query($pt, $businessId)->where($pts, '>=', $at)->select('id'));
        }

        return $this->query($t, $businessId);
    }

    public function keyOf(string $t, array $row): string
    {
        return implode(':', array_map(fn ($c) => (string) ($row[$c] ?? ''), $this->meta($t)['key']));
    }

    /** @return array<string,string> key column => value */
    public function keyWhere(string $t, string $key): array
    {
        return array_combine($this->meta($t)['key'], explode(':', $key));
    }

    public function label(string $t, array $r): string
    {
        if ($t === 'invoices' && empty($r['number'])) return 'Draft invoice #'.($r['id'] ?? '');
        if ($t === 'businesses') return 'Business settings';
        foreach (['number', 'display_name', 'legal_name', 'name', 'title', 'original_name', 'email', 'description', 'memo', 'reference', 'key'] as $c) {
            if (isset($r[$c]) && is_scalar($r[$c]) && trim((string) $r[$c]) !== '') {
                $label = (string) $r[$c];
                if ($t === 'contracts' && ! empty($r['title']) && $c === 'number') $label .= ' · '.$r['title'];

                return Str::limit($label, 180);
            }
        }

        return '#'.$this->keyOf($t, $r);
    }

    public static function title(string $t): string
    {
        return self::ENTITIES[$t] ?? Str::headline($t);
    }
}
