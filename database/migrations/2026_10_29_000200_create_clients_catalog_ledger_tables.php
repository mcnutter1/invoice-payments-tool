<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20); // asset, liability, equity, income, expense
            $table->string('subtype', 40)->nullable();
            $table->string('system_key', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'system_key']);
            $table->unique(['business_id', 'id']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('type', 20)->default('company');
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            foreach (['billing', 'service'] as $p) {
                $table->string("{$p}_line1")->nullable();
                $table->string("{$p}_line2")->nullable();
                $table->string("{$p}_city")->nullable();
                $table->string("{$p}_state", 60)->nullable();
                $table->string("{$p}_postal_code", 20)->nullable();
            }
            $table->unsignedSmallInteger('default_terms_days')->nullable();
            $table->boolean('tax_exempt')->default(false);
            $table->foreignId('tax_exempt_document_id')->nullable()->constrained('private_documents');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('external_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'display_name']);
            $table->index(['business_id', 'email']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('client_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('title')->nullable();
            $table->boolean('is_primary_billing')->default(false);
            $table->boolean('is_signer')->default(false);
            $table->timestamps();
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
        });

        Schema::create('products_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('sku', 60)->nullable();
            $table->string('kind', 20)->default('service');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('default_price', 15, 4)->default(0);
            $table->unsignedBigInteger('income_account_id');
            $table->unsignedBigInteger('cogs_account_id')->nullable();
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->boolean('active')->default(true);
            $table->string('default_cadence', 20)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'income_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
            $table->foreign(['business_id', 'cogs_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
            $table->foreign(['business_id', 'tax_rate_id'])->references(['business_id', 'id'])->on('tax_rates');
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('default_account_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'default_account_id'])->references(['business_id', 'id'])->on('chart_accounts');
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('action', 10); // close | reopen
            $table->date('closed_through')->nullable();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->date('entry_date');
            $table->string('memo')->nullable();
            $table->string('kind', 20)->default('auto'); // auto | manual | opening
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 120);
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['business_id', 'source_key']);
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'entry_date']);
            $table->index(['business_id', 'source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->date('entry_date');
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('reconciliation_id')->nullable();
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'journal_entry_id'])->references(['business_id', 'id'])->on('journal_entries');
            $table->foreign(['business_id', 'account_id'])->references(['business_id', 'id'])->on('chart_accounts');
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'vendor_id'])->references(['business_id', 'id'])->on('vendors');
            $table->index(['business_id', 'account_id', 'entry_date']);
            $table->index(['business_id', 'client_id']);
            $table->unique(['business_id', 'id']);
        });

        Schema::create('cash_basis_projection_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->date('event_date');
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('amount_cents'); // natural direction: + increases income (income acct) or expense (expense acct)
            $table->string('source_key', 120);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'account_id'])->references(['business_id', 'id'])->on('chart_accounts');
            $table->unique(['business_id', 'source_key', 'account_id'], 'cbpe_source_unique');
            $table->index(['business_id', 'event_date']);
        });
    }

    public function down(): void
    {
        foreach (['cash_basis_projection_events', 'journal_lines', 'journal_entries', 'accounting_periods', 'vendors',
            'products_services', 'contacts', 'clients', 'chart_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
