<?php

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

Route::get('/health', [C\HealthController::class, 'show']);

// ---------- Authentication (McNutt Cloud) ----------
Route::middleware('throttle:auth')->group(function () {
    Route::get('/auth/login', [C\AuthController::class, 'login'])->name('login');
    Route::get('/auth/callback', [C\AuthController::class, 'callback']);
    Route::post('/auth/logout', [C\AuthController::class, 'logout'])->name('logout');
    Route::get('/auth/dev-login', [C\AuthController::class, 'devForm']);
    Route::post('/auth/dev-login', [C\AuthController::class, 'devLogin']);
});

// ---------- Email assets (open pixel, logo) ----------
Route::middleware('throttle:tracking')->group(function () {
    Route::get('/t/o/{token}.gif', [C\EmailAssetController::class, 'pixel'])->where('token', '[A-Za-z0-9_-]{20,64}');
    Route::get('/brand/{ulid}/logo', [C\EmailAssetController::class, 'logo'])->where('ulid', '[0-9A-Za-z]{26}');
});

// ---------- Public client pages (no account) ----------
Route::middleware(['public.doc', 'throttle:public'])->group(function () {
    Route::get('/i/{token}', [C\PublicInvoiceController::class, 'exchange'])->middleware('throttle:public-token')->where('token', '[A-Za-z0-9_-]{40,64}');
    Route::get('/i/v/{ulid}', [C\PublicInvoiceController::class, 'show']);
    Route::get('/i/v/{ulid}/pay', [C\PublicInvoiceController::class, 'pay']);
    Route::post('/i/v/{ulid}/event', [C\PublicInvoiceController::class, 'event'])->withoutMiddleware('throttle:public')->middleware('throttle:tracking');
    Route::get('/i/v/{ulid}/pdf', [C\PublicInvoiceController::class, 'pdf']);
    Route::get('/i/v/{ulid}/paid-pdf', [C\PublicInvoiceController::class, 'paidPdf']);
    Route::get('/i/v/{ulid}/remittance', [C\PublicInvoiceController::class, 'remittance']);
    Route::get('/i/v/{ulid}/agreement', [C\PublicInvoiceController::class, 'agreement']);
    Route::get('/i/v/{ulid}/agreement/certificate', [C\PublicInvoiceController::class, 'agreementCertificate']);
    Route::post('/i/v/{ulid}/report-payment', [C\PublicInvoiceController::class, 'reportPayment'])->middleware('throttle:public-write');
    Route::post('/i/v/{ulid}/w9', [C\PublicW9Controller::class, 'requestFromInvoice'])->middleware('throttle:public-write');
    Route::post('/i/v/{ulid}/w9/download', [C\PublicW9Controller::class, 'downloadFromInvoice'])->middleware('throttle:public-write');
    Route::get('/w9/{token}', [C\PublicW9Controller::class, 'show'])->middleware('throttle:public-token')->where('token', '[A-Za-z0-9_-]{40,64}');
    Route::post('/w9/{token}', [C\PublicW9Controller::class, 'download'])->middleware('throttle:public-write')->where('token', '[A-Za-z0-9_-]{40,64}');

    Route::get('/c/{token}', [C\PublicContractController::class, 'exchange'])->middleware('throttle:public-token')->where('token', '[A-Za-z0-9_-]{40,64}');
    Route::get('/c/r/{recipient}', [C\PublicContractController::class, 'show'])->whereNumber('recipient');
    Route::get('/c/r/{recipient}/document', [C\PublicContractController::class, 'document'])->whereNumber('recipient');
    Route::get('/c/r/{recipient}/signed', [C\PublicContractController::class, 'signed'])->whereNumber('recipient');
    Route::get('/c/r/{recipient}/certificate', [C\PublicContractController::class, 'certificate'])->whereNumber('recipient');
    Route::get('/c/r/{recipient}/consent', [C\PublicContractController::class, 'consent'])->whereNumber('recipient');
    Route::post('/c/r/{recipient}/code', [C\PublicContractController::class, 'sendCode'])->middleware('throttle:public-write')->whereNumber('recipient');
    Route::post('/c/r/{recipient}/verify', [C\PublicContractController::class, 'verify'])->middleware('throttle:public-write')->whereNumber('recipient');
    Route::post('/c/r/{recipient}/sign', [C\PublicContractController::class, 'sign'])->middleware('throttle:public-write')->whereNumber('recipient');
    Route::post('/c/r/{recipient}/decline', [C\PublicContractController::class, 'decline'])->middleware('throttle:public-write')->whereNumber('recipient');
});

// ---------- Authenticated application ----------
Route::middleware('central')->group(function () {
    Route::get('/', [C\BusinessController::class, 'home']);
    Route::get('/businesses', [C\BusinessController::class, 'index'])->name('businesses');
    Route::get('/businesses/new', [C\BusinessController::class, 'create']);
    Route::post('/businesses', [C\BusinessController::class, 'store']);
    Route::get('/invite/{token}', [C\InvitationController::class, 'show'])->where('token', '[A-Za-z0-9_-]{40,64}');
    Route::post('/invite/{token}', [C\InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9_-]{40,64}');
    Route::get('/platform', [C\PlatformController::class, 'index']);
    Route::get('/platform/backups', [C\PlatformBackupController::class, 'index']);
    Route::post('/platform/backups/settings', [C\PlatformBackupController::class, 'saveSettings'])->middleware('central:fresh');
    Route::post('/platform/backups/test', [C\PlatformBackupController::class, 'test'])->middleware('throttle:sensitive');
    Route::post('/platform/backups/notify-test', [C\PlatformBackupController::class, 'testNotification'])->middleware('throttle:sensitive');
    Route::post('/platform/backups/businesses', [C\PlatformBackupController::class, 'saveBusinesses'])->middleware('central:fresh');
    Route::post('/platform/backups/businesses/{ulid}/run', [C\PlatformBackupController::class, 'run'])->middleware('throttle:sensitive');
    Route::post('/platform/role-mappings', [C\PlatformController::class, 'storeMapping'])->middleware('central:fresh');
    Route::post('/platform/role-mappings/{id}/delete', [C\PlatformController::class, 'deleteMapping'])->middleware('central:fresh');

    Route::prefix('/b/{business}')->middleware('business')->group(function () {
        Route::get('/', [C\DashboardController::class, 'show'])->name('dashboard');
        Route::get('/switch', [C\BusinessController::class, 'switch']);
        Route::get('/search', [C\DashboardController::class, 'search']);

        // Clients
        Route::middleware('perm:clients.view')->group(function () {
            Route::get('/clients', [C\ClientController::class, 'index']);
            Route::get('/clients/{id}', [C\ClientController::class, 'show'])->whereNumber('id');
            Route::get('/clients/{id}/statement', [C\ClientController::class, 'statement'])->whereNumber('id');
        });
        Route::middleware('perm:clients.manage')->group(function () {
            Route::get('/clients/new', [C\ClientController::class, 'create']);
            Route::post('/clients', [C\ClientController::class, 'store']);
            Route::get('/clients/{id}/edit', [C\ClientController::class, 'edit']);
            Route::post('/clients/{id}', [C\ClientController::class, 'update']);
            Route::post('/clients/{id}/archive', [C\ClientController::class, 'archive']);
            Route::post('/clients/{id}/contacts', [C\ClientController::class, 'addContact']);
            Route::post('/clients/{id}/contacts/{contact}', [C\ClientController::class, 'updateContact'])->whereNumber('contact');
            Route::post('/clients/{id}/contacts/{contact}/delete', [C\ClientController::class, 'removeContact']);
        });

        // Catalog, tax rates, vendors
        Route::get('/catalog', [C\CatalogController::class, 'index'])->middleware('perm:clients.view|catalog.manage');
        Route::middleware('perm:catalog.manage')->group(function () {
            Route::post('/catalog', [C\CatalogController::class, 'store']);
            Route::post('/catalog/{id}', [C\CatalogController::class, 'update']);
            Route::post('/tax-rates', [C\CatalogController::class, 'storeTaxRate']);
            Route::post('/tax-rates/{id}', [C\CatalogController::class, 'updateTaxRate']);
            Route::post('/vendors', [C\CatalogController::class, 'storeVendor']);
            Route::post('/vendors/{id}', [C\CatalogController::class, 'updateVendor']);
        });
        Route::get('/vendors', [C\CatalogController::class, 'vendors'])->middleware('perm:expenses.view|catalog.manage');

        // Invoices
        Route::middleware('perm:invoices.view')->group(function () {
            Route::get('/invoices', [C\InvoiceController::class, 'index']);
            Route::get('/invoices/{id}', [C\InvoiceController::class, 'show'])->whereNumber('id');
            Route::get('/invoices/{id}/pdf', [C\InvoiceController::class, 'pdf'])->whereNumber('id');
            Route::get('/invoices/{id}/preview', [C\InvoiceController::class, 'preview'])->whereNumber('id');
            Route::get('/invoices/{id}/documents/{version}', [C\InvoiceController::class, 'documentVersion'])->whereNumber('id');
        });
        Route::middleware('perm:invoices.manage')->group(function () {
            Route::get('/invoices/new', [C\InvoiceController::class, 'create']);
            Route::post('/invoices', [C\InvoiceController::class, 'store']);
            Route::get('/invoices/{id}/edit', [C\InvoiceController::class, 'edit']);
            Route::post('/invoices/{id}', [C\InvoiceController::class, 'update'])->whereNumber('id');
            Route::post('/invoices/{id}/delete', [C\InvoiceController::class, 'destroy']);
            Route::post('/invoices/{id}/duplicate', [C\InvoiceController::class, 'duplicate']);
        });
        Route::post('/invoices/{id}/issue', [C\InvoiceController::class, 'issue'])->middleware('perm:invoices.issue');
        Route::middleware('perm:invoices.send')->group(function () {
            Route::get('/invoices/{id}/send', [C\InvoiceController::class, 'sendForm']);
            Route::post('/invoices/{id}/send', [C\InvoiceController::class, 'send'])->middleware('throttle:sensitive');
            Route::post('/invoices/{id}/revoke-links', [C\InvoiceController::class, 'revokeLinks']);
            Route::post('/invoices/{id}/auto-reminders', [C\InvoiceController::class, 'toggleReminders']);
            Route::post('/clients/{id}/w9', [C\W9Controller::class, 'send'])->whereNumber('id')->middleware('throttle:sensitive');
            Route::get('/clients/{id}/w9', [C\W9Controller::class, 'download'])->whereNumber('id');
            Route::post('/invoices/bulk-send', [C\InvoiceController::class, 'bulkSend'])->middleware('throttle:sensitive');
            Route::post('/outbox/{id}/resend', [C\InvoiceController::class, 'resendReviewed']);
        });
        Route::middleware('perm:invoices.void')->group(function () {
            Route::post('/invoices/{id}/void', [C\InvoiceController::class, 'void']);
            Route::post('/invoices/{id}/purge', [C\InvoiceController::class, 'purge'])->middleware('central:fresh');
            Route::get('/invoices/{id}/credit', [C\InvoiceController::class, 'creditForm']);
            Route::post('/invoices/{id}/credit', [C\InvoiceController::class, 'credit']);
            Route::post('/invoices/{id}/write-off', [C\InvoiceController::class, 'writeOff']);
        });

        // Payments
        Route::middleware('perm:payments.view')->group(function () {
            Route::get('/payments', [C\PaymentController::class, 'index']);
            Route::get('/payments/{id}', [C\PaymentController::class, 'show'])->whereNumber('id');
            Route::get('/claims/{id}', [C\PaymentController::class, 'claim'])->whereNumber('id');
            Route::get('/claims/{id}/attachment', [C\PaymentController::class, 'claimAttachment'])->whereNumber('id');
        });
        Route::middleware('perm:payments.record')->group(function () {
            Route::get('/payments/new', [C\PaymentController::class, 'create']);
            Route::post('/payments', [C\PaymentController::class, 'store']);
            Route::post('/payments/{id}/apply', [C\PaymentController::class, 'apply']);
            Route::post('/payments/{id}/deposit', [C\PaymentController::class, 'deposit']);
            Route::post('/payments/{id}/clear', [C\PaymentController::class, 'clear']);
        });
        Route::middleware('perm:payments.confirm')->group(function () {
            Route::post('/claims/{id}/confirm', [C\PaymentController::class, 'confirmClaim'])->middleware('central:fresh');
            Route::post('/claims/{id}/reject', [C\PaymentController::class, 'rejectClaim']);
            Route::post('/claims/{id}/clarify', [C\PaymentController::class, 'clarifyClaim']);
        });
        Route::middleware(['perm:payments.reverse', 'central:fresh'])->group(function () {
            Route::post('/payments/{id}/reverse', [C\PaymentController::class, 'reverse']);
            Route::post('/allocations/{id}/unapply', [C\PaymentController::class, 'unapply']);
            Route::post('/payments/{id}/refund', [C\PaymentController::class, 'refund']);
        });

        // Expenses & bills
        Route::middleware('perm:expenses.view')->group(function () {
            Route::get('/expenses', [C\ExpenseController::class, 'index']);
            Route::get('/expenses/{id}', [C\ExpenseController::class, 'show'])->whereNumber('id');
            Route::get('/expenses/{id}/receipt', [C\ExpenseController::class, 'receipt'])->whereNumber('id');
        });
        Route::middleware('perm:expenses.manage')->group(function () {
            Route::get('/expenses/new', [C\ExpenseController::class, 'create']);
            Route::post('/expenses', [C\ExpenseController::class, 'store']);
            Route::post('/expenses/{id}/void', [C\ExpenseController::class, 'void']);
            Route::post('/expenses/{id}/pay', [C\ExpenseController::class, 'pay']);
            Route::post('/expenses/{id}/receipt', [C\ExpenseController::class, 'attachReceipt']);
        });
        Route::post('/expenses/{id}/bill-client', [C\ExpenseController::class, 'billToClient'])->middleware('perm:invoices.manage');

        // Banking
        Route::middleware('perm:banking.view')->group(function () {
            Route::get('/banking', [C\BankingController::class, 'index']);
            Route::get('/banking/{id}', [C\BankingController::class, 'account'])->whereNumber('id');
            Route::get('/reconciliations/{id}', [C\BankingController::class, 'reconciliation'])->whereNumber('id');
        });
        Route::middleware('perm:banking.manage')->group(function () {
            Route::post('/banking', [C\BankingController::class, 'storeAccount']);
            Route::post('/banking/{id}/import', [C\BankingController::class, 'upload']);
            Route::get('/banking/{id}/import/{doc}', [C\BankingController::class, 'preview']);
            Route::post('/banking/{id}/import/{doc}', [C\BankingController::class, 'import']);
            Route::post('/imports/{id}/undo', [C\BankingController::class, 'undoImport']);
            Route::post('/bank-transactions/{id}/match', [C\BankingController::class, 'match']);
            Route::post('/bank-transactions/{id}/categorize', [C\BankingController::class, 'categorize']);
            Route::post('/bank-transactions/{id}/exclude', [C\BankingController::class, 'exclude']);
            Route::post('/bank-transactions/{id}/undo', [C\BankingController::class, 'undo']);
            Route::post('/banking/{id}/reconcile', [C\BankingController::class, 'startReconciliation']);
            Route::post('/reconciliations/{id}', [C\BankingController::class, 'completeReconciliation']);
            Route::post('/reconciliations/{id}/cancel', [C\BankingController::class, 'cancelReconciliation']);
            Route::post('/reconciliations/{id}/undo', [C\BankingController::class, 'undoReconciliation']);
            Route::get('/movements/new', [C\BankingController::class, 'movementForm']);
            Route::post('/movements', [C\BankingController::class, 'storeMovement']);
        });

        // Accounting
        Route::middleware('perm:journals.view')->group(function () {
            Route::get('/accounts', [C\AccountingController::class, 'accounts']);
            Route::get('/journals', [C\AccountingController::class, 'journals']);
            Route::get('/journals/{id}', [C\AccountingController::class, 'entry'])->whereNumber('id');
            Route::get('/periods', [C\AccountingController::class, 'periods']);
        });
        Route::middleware(['perm:journals.manage'])->group(function () {
            Route::post('/accounts', [C\AccountingController::class, 'storeAccount']);
            Route::post('/accounts/{id}', [C\AccountingController::class, 'updateAccount']);
            Route::get('/journals/new', [C\AccountingController::class, 'createJournal']);
            Route::post('/journals', [C\AccountingController::class, 'storeJournal'])->middleware('central:fresh');
            Route::post('/journals/{id}/reverse', [C\AccountingController::class, 'reverseJournal'])->middleware('central:fresh');
        });
        Route::post('/periods/close', [C\AccountingController::class, 'close'])->middleware(['perm:books.close', 'central:fresh']);
        Route::post('/periods/reopen', [C\AccountingController::class, 'reopen'])->middleware(['perm:books.reopen', 'central:fresh']);

        // Reports
        Route::get('/reports', [C\ReportController::class, 'index'])->middleware('perm:reports.ar.view|reports.pnl.view');
        Route::get('/reports/{report}', [C\ReportController::class, 'show'])->middleware('perm:reports.ar.view|reports.pnl.view');

        // Contracts
        Route::middleware('perm:contracts.view')->group(function () {
            Route::get('/contracts', [C\ContractController::class, 'index']);
            Route::get('/contracts/{id}', [C\ContractController::class, 'show'])->whereNumber('id');
            Route::get('/contracts/{id}/versions/{version}/{kind}', [C\ContractController::class, 'download'])->whereNumber('id')->whereIn('kind', ['document', 'signed', 'certificate']);
            Route::get('/contracts/{id}/versions/{version}/preview', [C\ContractController::class, 'preview'])->whereNumber('id')->whereNumber('version');
            Route::get('/contract-templates', [C\ContractController::class, 'templates']);
        });
        Route::middleware('perm:contracts.manage')->group(function () {
            Route::get('/contracts/new', [C\ContractController::class, 'create']);
            Route::post('/contracts', [C\ContractController::class, 'store']);
            Route::get('/contracts/{id}/edit', [C\ContractController::class, 'edit']);
            Route::post('/contracts/{id}', [C\ContractController::class, 'update'])->whereNumber('id');
            Route::post('/contracts/{id}/prepare', [C\ContractController::class, 'prepare']);
            Route::post('/contracts/{id}/new-version', [C\ContractController::class, 'newVersion']);
            Route::post('/contracts/{id}/amend', [C\ContractController::class, 'amend']);
            Route::post('/contracts/{id}/external', [C\ContractController::class, 'uploadExternal']);
            Route::post('/contracts/{id}/terminate', [C\ContractController::class, 'terminate']);
            Route::post('/contracts/{id}/reactivate', [C\ContractController::class, 'reactivate']);
            Route::post('/contracts/{id}/renew', [C\ContractController::class, 'renew']);
            Route::post('/contracts/{id}/delete', [C\ContractController::class, 'destroy']);
            Route::post('/contracts/{id}/billing-override', [C\ContractController::class, 'billingOverride'])->middleware('central:fresh');
            Route::post('/contract-templates', [C\ContractController::class, 'storeTemplate']);
            Route::post('/contract-templates/{id}', [C\ContractController::class, 'updateTemplate']);
        });
        Route::middleware('perm:contracts.send')->group(function () {
            Route::post('/contracts/{id}/send', [C\ContractController::class, 'send']);
            Route::post('/contracts/{id}/void', [C\ContractController::class, 'void']);
            Route::post('/contract-recipients/{id}/remind', [C\ContractController::class, 'remind']);
            Route::post('/contracts/{id}/auto-reminders', [C\ContractController::class, 'toggleReminders']);
            Route::post('/contracts/{id}/send-executed', [C\ContractController::class, 'sendExecuted'])->middleware('throttle:sensitive');
        });
        Route::post('/contracts/{id}/milestones/{milestone}/invoice', [C\ContractController::class, 'invoiceMilestone'])->middleware('perm:invoices.manage');
        Route::post('/contracts/{id}/billing/run', [C\ContractController::class, 'runBilling'])->middleware('perm:invoices.manage');
        Route::get('/contracts/{id}/countersign', [C\ContractController::class, 'countersignForm'])->middleware('perm:contracts.countersign');
        Route::post('/contracts/{id}/countersign', [C\ContractController::class, 'countersign'])->middleware(['perm:contracts.countersign', 'central:fresh']);
        Route::post('/my-signature/delete', [C\ContractController::class, 'forgetSignature'])->middleware('perm:contracts.countersign');
        Route::get('/my-signature', [C\ContractController::class, 'mySignature'])->middleware('perm:contracts.countersign');
        Route::post('/my-signature', [C\ContractController::class, 'uploadSignature'])->middleware(['perm:contracts.countersign', 'throttle:sensitive']);

        // Recurring billing
        Route::middleware('perm:recurring.view')->group(function () {
            Route::get('/recurring', [C\RecurringController::class, 'index']);
            Route::get('/recurring/{id}', [C\RecurringController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('perm:recurring.manage')->group(function () {
            Route::get('/recurring/new', [C\RecurringController::class, 'create']);
            Route::post('/recurring', [C\RecurringController::class, 'store']);
            Route::get('/recurring/{id}/edit', [C\RecurringController::class, 'edit']);
            Route::post('/recurring/{id}', [C\RecurringController::class, 'update'])->whereNumber('id');
            Route::post('/recurring/{id}/status', [C\RecurringController::class, 'status']);
            Route::post('/schedule-runs/{id}/generate', [C\RecurringController::class, 'generateRun']);
            Route::post('/schedule-runs/{id}/skip', [C\RecurringController::class, 'skipRun']);
        });

        // Settings
        Route::get('/settings', [C\SettingsController::class, 'index'])->middleware('perm:business.view');
        Route::get('/settings/logo', [C\SettingsController::class, 'logo'])->middleware('perm:business.view');
        Route::middleware('perm:business.manage')->group(function () {
            Route::post('/settings/profile', [C\SettingsController::class, 'updateProfile']);
            Route::post('/settings/reminders', [C\SettingsController::class, 'updateReminders']);
            Route::post('/settings/w9', [C\W9Controller::class, 'update'])->middleware('central:fresh');
            Route::get('/settings/w9/preview', [C\W9Controller::class, 'preview']);
            Route::post('/settings/branding', [C\SettingsController::class, 'updateBranding']);
            Route::post('/settings/payments', [C\SettingsController::class, 'updatePayments'])->middleware('central:fresh');
            Route::post('/settings/email', [C\SettingsController::class, 'updateEmail']);
            Route::post('/settings/email/test', [C\SettingsController::class, 'testEmail'])->middleware('throttle:sensitive');
            Route::get('/settings/preview-pdf', [C\SettingsController::class, 'previewPdf']);
        });
        Route::middleware('perm:members.manage')->group(function () {
            Route::get('/settings/members', [C\MemberController::class, 'index']);
            Route::post('/settings/members/invite', [C\MemberController::class, 'invite'])->middleware('central:fresh');
            Route::post('/settings/members/{id}/roles', [C\MemberController::class, 'updateRoles'])->middleware('central:fresh');
            Route::post('/settings/members/{id}/remove', [C\MemberController::class, 'remove'])->middleware('central:fresh');
            Route::post('/settings/members/{id}/approve', [C\MemberController::class, 'approve'])->middleware('central:fresh');
            Route::post('/settings/invitations/{id}/revoke', [C\MemberController::class, 'revokeInvitation']);
        });
        Route::middleware('perm:roles.manage')->group(function () {
            Route::get('/settings/roles', [C\MemberController::class, 'roles']);
            Route::post('/settings/roles', [C\MemberController::class, 'storeRole'])->middleware('central:fresh');
            Route::post('/settings/roles/{id}', [C\MemberController::class, 'updateRole'])->middleware('central:fresh');
        });
        Route::post('/settings/ownership', [C\MemberController::class, 'transferOwnership'])->middleware(['perm:ownership.transfer', 'central:fresh']);
        Route::get('/automation', [C\OperationsController::class, 'automation'])->middleware('perm:automation.view');
        Route::get('/audit', [C\OperationsController::class, 'audit'])->middleware('perm:audit.view');

        // Backups (explorer, previews and restore)
        Route::middleware('perm:business.manage')->group(function () {
            Route::get('/backups', [C\BackupController::class, 'index']);
            Route::post('/backups/run', [C\BackupController::class, 'run'])->middleware('throttle:sensitive');
            Route::get('/backups/records/{table}/{key}', [C\BackupController::class, 'record'])->where(['table' => '[a-z_]+', 'key' => '[A-Za-z0-9:_-]+']);
            Route::get('/backups/files/{document}', [C\BackupController::class, 'file'])->whereNumber('document');
            Route::get('/backups/{snapshot}', [C\BackupController::class, 'snapshotShow'])->where('snapshot', '[0-9a-z]{26}');
            Route::get('/backups/{snapshot}/export', [C\BackupController::class, 'export'])->where('snapshot', '[0-9a-z]{26}')->middleware('central:fresh');
            Route::post('/backups/{snapshot}/records/{table}/{key}/restore', [C\BackupController::class, 'restoreRecord'])
                ->where(['snapshot' => '[0-9a-z]{26}', 'table' => '[a-z_]+', 'key' => '[A-Za-z0-9:_-]+']);
            Route::post('/backups/{snapshot}/rollback', [C\BackupController::class, 'rollback'])->where('snapshot', '[0-9a-z]{26}')->middleware('central:fresh');
        });

        // Migration (CSV import wizard)
        Route::middleware(['perm:journals.manage', 'perm:clients.manage'])->group(function () {
            Route::get('/migration', [C\MigrationController::class, 'index']);
            Route::get('/migration/template/{kind}', [C\MigrationController::class, 'template']);
            Route::post('/migration', [C\MigrationController::class, 'upload']);
            Route::get('/migration/{kind}/{doc}', [C\MigrationController::class, 'preview']);
            Route::post('/migration/{kind}/{doc}', [C\MigrationController::class, 'commit']);
        });
    });
});
