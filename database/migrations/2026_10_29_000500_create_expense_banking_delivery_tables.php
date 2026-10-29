<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('kind', 10)->default('expense'); // expense (paid at once) | bill (AP)
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('reference', 80)->nullable();
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->string('description')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('total_cents');
            $table->bigInteger('paid_cents')->default(0);
            $table->string('status', 20)->default('paid'); // paid | unpaid | partial | void
            $table->unsignedBigInteger('payment_account_id')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('tax_treatment', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('receipt_document_id')->nullable()->constrained('private_documents');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->boolean('possible_duplicate')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'expense_date']);
            $table->index(['business_id', 'vendor_id']);
            $table->foreign(['business_id', 'vendor_id'])->references(['business_id', 'id'])->on('vendors');
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'payment_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('expense_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('account_id');
            $table->string('description')->nullable();
            $table->bigInteger('amount_cents');
            $table->boolean('billable')->default(false);
            $table->unsignedBigInteger('billed_invoice_id')->nullable();
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'expense_id'])->references(['business_id', 'id'])->on('expenses');
            $table->foreign(['business_id', 'account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('expense_id');
            $table->bigInteger('amount_cents');
            $table->date('payment_date');
            $table->unsignedBigInteger('account_id');
            $table->string('method', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'expense_id'])->references(['business_id', 'id'])->on('expenses');
            $table->foreign(['business_id', 'account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('money_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('kind', 30); // transfer | owner_contribution | owner_draw | loan_proceeds | loan_payment
            $table->date('movement_date');
            $table->bigInteger('amount_cents');
            $table->unsignedBigInteger('from_account_id');
            $table->unsignedBigInteger('to_account_id');
            $table->string('memo')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign(['business_id', 'from_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
            $table->foreign(['business_id', 'to_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->unsignedBigInteger('chart_account_id');
            $table->string('name');
            $table->string('institution')->nullable();
            $table->string('last4', 4)->nullable();
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->date('opening_date')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->unique(['business_id', 'chart_account_id']);
            $table->foreign(['business_id', 'chart_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('kind', 30);
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('filename');
            $table->string('status', 20)->default('imported');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('mapping')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->date('posted_date');
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->char('fingerprint', 64);
            $table->string('status', 20)->default('unmatched'); // unmatched | matched | posted | excluded
            $table->unsignedBigInteger('matched_journal_line_id')->nullable();
            $table->unsignedBigInteger('created_journal_entry_id')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users');
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'bank_account_id', 'fingerprint'], 'bank_txn_fingerprint_unique');
            $table->index(['business_id', 'bank_account_id', 'status']);
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'bank_account_id'])->references(['business_id', 'id'])->on('bank_accounts');
            $table->foreign(['business_id', 'import_batch_id'])->references(['business_id', 'id'])->on('import_batches');
            $table->foreign(['business_id', 'matched_journal_line_id'])->references(['business_id', 'id'])->on('journal_lines');
        });

        Schema::create('reconciliation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->unsignedBigInteger('bank_account_id');
            $table->date('statement_date');
            $table->bigInteger('statement_opening_cents');
            $table->bigInteger('statement_closing_cents');
            $table->string('status', 20)->default('in_progress');
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'bank_account_id'])->references(['business_id', 'id'])->on('bank_accounts');
        });

        Schema::create('public_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('purpose', 30); // invoice_view | contract_sign | contract_view
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('contract_version_id')->nullable();
            $table->unsignedBigInteger('contract_recipient_id')->nullable();
            $table->json('allowed_actions')->nullable();
            $table->string('recipient_email')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign(['business_id', 'invoice_id'])->references(['business_id', 'id'])->on('invoices');
            $table->foreign(['business_id', 'contract_version_id'])->references(['business_id', 'id'])->on('contract_versions');
            $table->foreign(['business_id', 'contract_recipient_id'])->references(['business_id', 'id'])->on('contract_recipients');
        });

        Schema::create('email_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->ulid('ulid')->unique();
            $table->string('correlation_id', 64);
            $table->string('kind', 40);
            $table->string('to_email');
            $table->string('from_header');
            $table->string('subject');
            $table->text('encrypted_body')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(6);
            $table->timestamp('available_at');
            $table->text('last_error')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('invoice_document_version_id')->nullable();
            $table->unsignedBigInteger('contract_version_id')->nullable();
            $table->unsignedBigInteger('contract_recipient_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['business_id', 'invoice_id']);
        });

        Schema::create('email_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_outbox_id')->constrained('email_outbox');
            $table->unsignedTinyInteger('attempt_no');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('result', 30);
            $table->text('error')->nullable();
        });

        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained();
            $table->string('provider', 40);
            $table->string('provider_event_id', 120);
            $table->string('event', 40);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'provider_event_id']);
        });

        Schema::create('document_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->unsignedBigInteger('public_token_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('contract_version_id')->nullable();
            $table->string('event', 30); // link_accessed | invoice_viewed | pdf_downloaded | open_detected | contract_viewed
            $table->boolean('probable_automation')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        foreach (['document_access_events', 'provider_events', 'email_attempts', 'email_outbox', 'public_tokens',
            'reconciliation_sessions', 'bank_transactions', 'import_batches', 'bank_accounts', 'money_movements',
            'vendor_payments', 'expense_lines', 'expenses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
