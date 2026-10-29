<?php

namespace App\Support;

final class Permissions
{
    /** key => [group, description] */
    public const ALL = [
        'business.view' => ['Business', 'View business profile and settings'],
        'business.manage' => ['Business', 'Edit business profile, branding, email and payment settings'],
        'members.manage' => ['Business', 'Invite, remove and change roles of members'],
        'roles.manage' => ['Business', 'Create and edit custom roles'],
        'ownership.transfer' => ['Business', 'Transfer business ownership'],
        'clients.view' => ['Clients', 'View clients'],
        'clients.manage' => ['Clients', 'Create and edit clients'],
        'catalog.manage' => ['Clients', 'Manage products, services, tax rates and vendors'],
        'invoices.view' => ['Invoicing', 'View invoices'],
        'invoices.manage' => ['Invoicing', 'Create and edit draft invoices'],
        'invoices.issue' => ['Invoicing', 'Issue invoices (posts to the ledger)'],
        'invoices.send' => ['Invoicing', 'Send invoices and reminders'],
        'invoices.void' => ['Invoicing', 'Void invoices and issue credit notes / write-offs'],
        'recurring.view' => ['Invoicing', 'View recurring billing'],
        'recurring.manage' => ['Invoicing', 'Manage recurring billing schedules'],
        'contracts.view' => ['Contracts', 'View contracts'],
        'contracts.manage' => ['Contracts', 'Author contracts and templates'],
        'contracts.send' => ['Contracts', 'Send and void contracts'],
        'contracts.countersign' => ['Contracts', 'Countersign contracts for the business'],
        'payments.view' => ['Payments', 'View payments and claims'],
        'payments.record' => ['Payments', 'Record received payments'],
        'payments.confirm' => ['Payments', 'Confirm or reject client payment claims'],
        'payments.reverse' => ['Payments', 'Reverse payments, refunds and reallocations'],
        'expenses.view' => ['Expenses', 'View expenses and bills'],
        'expenses.manage' => ['Expenses', 'Record expenses, bills and vendor payments'],
        'banking.view' => ['Banking', 'View bank accounts and imports'],
        'banking.manage' => ['Banking', 'Import statements, match and reconcile'],
        'journals.view' => ['Accounting', 'View chart of accounts and journals'],
        'journals.manage' => ['Accounting', 'Manage chart of accounts and post manual journals'],
        'reports.ar.view' => ['Reports', 'View AR, aging and invoice reports'],
        'reports.pnl.view' => ['Reports', 'View P&L, balance sheet and all financial reports'],
        'reports.export' => ['Reports', 'Export reports to CSV/PDF'],
        'books.close' => ['Accounting', 'Close accounting periods'],
        'books.reopen' => ['Accounting', 'Reopen closed accounting periods'],
        'automation.view' => ['Operations', 'View the automation monitor'],
        'audit.view' => ['Operations', 'View the audit log'],
    ];

    public const SYSTEM_ROLES = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'accountant' => 'Accountant',
        'billing' => 'Billing',
        'viewer' => 'Viewer',
    ];

    /** @return list<string> */
    public static function defaultsFor(string $role): array
    {
        $all = array_keys(self::ALL);

        return match ($role) {
            'owner' => $all,
            'admin' => array_values(array_diff($all, ['ownership.transfer', 'books.close', 'books.reopen'])),
            'accountant' => [
                'business.view', 'clients.view', 'clients.manage', 'catalog.manage',
                'invoices.view', 'invoices.manage', 'invoices.issue', 'invoices.send', 'invoices.void',
                'recurring.view', 'recurring.manage', 'contracts.view',
                'payments.view', 'payments.record', 'payments.confirm', 'payments.reverse',
                'expenses.view', 'expenses.manage', 'banking.view', 'banking.manage',
                'journals.view', 'journals.manage', 'reports.ar.view', 'reports.pnl.view', 'reports.export',
                'automation.view', 'audit.view',
            ],
            'billing' => [
                'business.view', 'clients.view', 'clients.manage', 'catalog.manage',
                'invoices.view', 'invoices.manage', 'invoices.issue', 'invoices.send',
                'recurring.view', 'recurring.manage', 'contracts.view', 'payments.view', 'reports.ar.view',
            ],
            'viewer' => [
                'business.view', 'clients.view', 'invoices.view', 'recurring.view', 'contracts.view', 'payments.view',
            ],
            default => [],
        };
    }

    /** Central (platform) capabilities that may be mapped from verified central roles. */
    public const CENTRAL_CAPABILITIES = [
        'businesses.create' => 'Create new businesses',
        'platform.operations' => 'View platform operational health (no tenant financial access)',
    ];
}
