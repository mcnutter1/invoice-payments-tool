<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('ach_account_name')->nullable();
            $table->string('ach_bank_name')->nullable();
            $table->string('ach_bank_location', 120)->nullable();
            $table->text('ach_bank_address')->nullable();
            $table->string('ach_routing_number', 9)->nullable();
            $table->string('ach_wire_routing_number', 9)->nullable();
            $table->text('ach_account_number_encrypted')->nullable();
            $table->string('ach_account_type', 10)->nullable(); // checking | savings
            $table->string('ach_swift_code', 11)->nullable();
            $table->text('ach_instructions')->nullable();
            $table->string('venmo_handle', 60)->nullable();
            $table->decimal('venmo_fee_percent', 6, 3)->default(0);
            $table->unsignedInteger('venmo_fee_fixed_cents')->default(0);
            $table->boolean('venmo_auto_confirm')->default(false);
            $table->char('venmo_api_token_hash', 64)->nullable()->unique();
            $table->timestamp('venmo_api_token_created_at')->nullable();
            $table->text('stripe_secret_key_encrypted')->nullable();
            $table->text('stripe_webhook_secret_encrypted')->nullable();
            $table->string('stripe_account_label')->nullable();
            $table->boolean('stripe_live')->default(false);
            $table->unsignedBigInteger('stripe_deposit_account_id')->nullable();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->json('payment_methods')->nullable(); // null = business defaults
            $table->string('stripe_customer_id', 64)->nullable();
        });

        Schema::table('recurring_schedules', function (Blueprint $table) {
            $table->boolean('card_autopay')->default(false);
            $table->string('stripe_payment_method_id', 64)->nullable();
            $table->string('autopay_card_label', 60)->nullable();
            $table->timestamp('autopay_authorized_at')->nullable();
            $table->string('autopay_authorized_email')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('fee_cents')->default(0);
            $table->unsignedBigInteger('fee_account_id')->nullable();
            $table->string('processor', 20)->nullable(); // stripe | venmo_api
            $table->string('external_id', 120)->nullable();
            $table->unique(['business_id', 'external_id']);
        });

        Schema::table('payment_claims', function (Blueprint $table) {
            $table->string('source', 20)->default('client'); // client | venmo_api
            $table->string('external_id', 120)->nullable();
        });

        // Processing fees post to "Bank & Merchant Fees".
        foreach (DB::table('businesses')->pluck('id') as $bid) {
            $has = DB::table('chart_accounts')->where('business_id', $bid)->where('system_key', 'merchant_fees')->exists();
            if ($has) continue;
            $row = DB::table('chart_accounts')->where('business_id', $bid)->where('code', '6100')->whereNull('system_key')->first();
            if ($row) {
                DB::table('chart_accounts')->where('id', $row->id)->update(['system_key' => 'merchant_fees']);
            } else {
                DB::table('chart_accounts')->insert(['business_id' => $bid, 'code' => DB::table('chart_accounts')->where('business_id', $bid)->where('code', '6100')->exists() ? '6105' : '6100',
                    'name' => 'Bank & Merchant Fees', 'type' => 'expense', 'subtype' => 'operating', 'system_key' => 'merchant_fees', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payment_claims', fn (Blueprint $t) => $t->dropColumn(['source', 'external_id']));
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['business_id', 'external_id']);
            $t->dropColumn(['fee_cents', 'fee_account_id', 'processor', 'external_id']);
        });
        Schema::table('recurring_schedules', fn (Blueprint $t) => $t->dropColumn(['card_autopay', 'stripe_payment_method_id', 'autopay_card_label', 'autopay_authorized_at', 'autopay_authorized_email']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['payment_methods', 'stripe_customer_id']));
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn([
            'ach_account_name', 'ach_bank_name', 'ach_bank_location', 'ach_bank_address', 'ach_routing_number', 'ach_wire_routing_number',
            'ach_account_number_encrypted', 'ach_account_type', 'ach_swift_code', 'ach_instructions', 'venmo_handle', 'venmo_fee_percent',
            'venmo_fee_fixed_cents', 'venmo_auto_confirm', 'venmo_api_token_hash', 'venmo_api_token_created_at', 'stripe_secret_key_encrypted',
            'stripe_webhook_secret_encrypted', 'stripe_account_label', 'stripe_live', 'stripe_deposit_account_id',
        ]));
    }
};
