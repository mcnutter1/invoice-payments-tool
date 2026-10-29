<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('kind', 20);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unique(['business_id', 'kind']);
        });

        Schema::create('recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('contract_version_id')->nullable();
            $table->string('name');
            $table->string('cadence', 10); // week | month | quarter | year
            $table->unsignedSmallInteger('interval')->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_run_date')->nullable();
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->string('timezone', 64)->default('America/New_York');
            $table->unsignedSmallInteger('terms_days')->default(30);
            $table->boolean('auto_issue')->default(false);
            $table->boolean('auto_send')->default(false);
            $table->json('recipients')->nullable();
            $table->json('payment_methods')->nullable();
            $table->text('customer_note')->nullable();
            $table->string('price_policy', 20)->default('snapshot');
            $table->string('status', 20)->default('active');
            $table->boolean('require_signed_contract')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->index(['status', 'next_run_date']);
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'contract_id'])->references(['business_id', 'id'])->on('contracts');
            $table->foreign(['business_id', 'contract_version_id'])->references(['business_id', 'id'])->on('contract_versions');
        });

        Schema::create('recurring_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('recurring_schedule_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->unsignedBigInteger('income_account_id');
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->foreign(['business_id', 'recurring_schedule_id'])->references(['business_id', 'id'])->on('recurring_schedules')->cascadeOnDelete();
            $table->foreign(['business_id', 'income_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('client_id');
            $table->string('number', 40)->nullable();
            $table->string('status', 20)->default('draft'); // draft | issued | void
            $table->string('delivery_status', 20)->default('not_sent');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('service_start')->nullable();
            $table->date('service_end')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('po_number', 80)->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('contract_version_id')->nullable();
            $table->unsignedBigInteger('recurring_schedule_id')->nullable();
            $table->string('discount_type', 10)->default('none');
            $table->decimal('discount_value', 15, 4)->default(0);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->bigInteger('credited_cents')->default(0);
            $table->bigInteger('written_off_cents')->default(0);
            $table->bigInteger('balance_cents')->default(0);
            $table->string('settlement_status', 20)->default('unpaid');
            $table->text('customer_note')->nullable();
            $table->text('terms')->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('payment_methods')->nullable();
            $table->json('recipients')->nullable();
            $table->json('client_snapshot')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->json('instructions_snapshot')->nullable();
            $table->json('template_snapshot')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedBigInteger('issue_journal_entry_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('replaces_invoice_id')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'status', 'due_date']);
            $table->index(['business_id', 'client_id']);
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'contract_id'])->references(['business_id', 'id'])->on('contracts');
            $table->foreign(['business_id', 'contract_version_id'])->references(['business_id', 'id'])->on('contract_versions');
            $table->foreign(['business_id', 'recurring_schedule_id'])->references(['business_id', 'id'])->on('recurring_schedules');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('sku', 60)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->string('unit', 30)->nullable();
            $table->unsignedBigInteger('income_account_id');
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 9, 4)->default(0);
            $table->bigInteger('amount_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('contract_milestone_id')->nullable();
            $table->unsignedBigInteger('source_expense_line_id')->nullable();
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'invoice_id'])->references(['business_id', 'id'])->on('invoices')->cascadeOnDelete();
            $table->foreign(['business_id', 'income_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('invoice_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->string('name');
            $table->decimal('rate', 9, 4);
            $table->bigInteger('taxable_cents');
            $table->bigInteger('tax_cents');
            $table->foreign(['business_id', 'invoice_id'])->references(['business_id', 'id'])->on('invoices')->cascadeOnDelete();
        });

        Schema::create('invoice_document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedInteger('version');
            $table->foreignId('document_id')->constrained('private_documents');
            $table->char('sha256', 64);
            $table->string('reason', 60)->default('issue');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['invoice_id', 'version']);
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'invoice_id'])->references(['business_id', 'id'])->on('invoices');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('number', 40);
            $table->string('kind', 20)->default('credit'); // credit | write_off
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->date('issue_date');
            $table->text('reason')->nullable();
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->string('status', 20)->default('issued');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'invoice_id'])->references(['business_id', 'id'])->on('invoices');
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('credit_note_id');
            $table->string('description');
            $table->unsignedBigInteger('income_account_id');
            $table->bigInteger('amount_cents');
            $table->decimal('tax_rate', 9, 4)->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->foreign(['business_id', 'credit_note_id'])->references(['business_id', 'id'])->on('credit_notes');
            $table->foreign(['business_id', 'income_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->unsignedBigInteger('invoice_id');
            $table->string('method', 20);
            $table->bigInteger('reported_amount_cents');
            $table->date('reported_date');
            $table->string('reference', 120)->nullable();
            $table->string('check_number', 40)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('attachment_document_id')->nullable()->constrained('private_documents');
            $table->string('status', 30)->default('reported'); // reported | confirmed | rejected | clarification_requested
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'status']);
            $table->foreign(['business_id', 'invoice_id'])->references(['business_id', 'id'])->on('invoices');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('client_id');
            $table->string('method', 20); // zelle | check | cash | other
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_cents');
            $table->date('received_date');
            $table->string('status', 20)->default('recorded'); // recorded | deposited | cleared | reversed
            $table->unsignedBigInteger('deposit_account_id');
            $table->string('reference', 120)->nullable();
            $table->string('check_number', 40)->nullable();
            $table->text('memo')->nullable();
            $table->unsignedBigInteger('claim_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('deposit_journal_entry_id')->nullable();
            $table->date('deposited_date')->nullable();
            $table->date('cleared_date')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->string('reversal_kind', 20)->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('receipt_emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->unique(['business_id', 'claim_id']);
            $table->index(['business_id', 'received_date']);
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'deposit_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('credit_note_id')->nullable();
            $table->unsignedBigInteger('invoice_id');
            $table->bigInteger('amount_cents');
            $table->date('applied_date');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'payment_id'])->references(['business_id', 'id'])->on('payments');
            $table->foreign(['business_id', 'credit_note_id'])->references(['business_id', 'id'])->on('credit_notes');
            $table->foreign(['business_id', 'invoice_id'])->references(['business_id', 'id'])->on('invoices');
            $table->index(['business_id', 'invoice_id', 'status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->bigInteger('amount_cents');
            $table->date('refund_date');
            $table->unsignedBigInteger('account_id');
            $table->string('method', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'payment_id'])->references(['business_id', 'id'])->on('payments');
        });

        Schema::create('schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('recurring_schedule_id');
            $table->string('billing_period_key', 20);
            $table->date('period_date');
            $table->string('status', 20); // generated | needs_review | skipped | failed
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'recurring_schedule_id', 'billing_period_key'], 'schedule_runs_period_unique');
            $table->foreign(['business_id', 'recurring_schedule_id'])->references(['business_id', 'id'])->on('recurring_schedules');
        });
    }

    public function down(): void
    {
        foreach (['schedule_runs', 'refunds', 'payment_allocations', 'payments', 'payment_claims', 'credit_note_lines', 'credit_notes',
            'invoice_document_versions', 'invoice_tax_lines', 'invoice_lines', 'invoices', 'recurring_line_items',
            'recurring_schedules', 'invoice_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
