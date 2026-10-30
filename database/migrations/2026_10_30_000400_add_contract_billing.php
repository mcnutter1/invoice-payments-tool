<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'billing_monthly_cents')) return; // tolerate a partially applied earlier run
            $table->bigInteger('billing_monthly_cents')->nullable();
            $table->string('billing_monthly_description', 200)->nullable();
            $table->date('billing_start_date')->nullable();
            $table->boolean('billing_auto')->default(true);
            $table->string('billing_mode', 20)->default('draft'); // draft | issue | issue_send
        });
        Schema::table('contract_billing_milestones', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
        Schema::table('recurring_schedules', function (Blueprint $table) {
            $table->string('origin', 20)->nullable(); // null = created by staff, 'contract' = generated from agreement billing
        });
    }

    public function down(): void
    {
        Schema::table('recurring_schedules', fn (Blueprint $t) => $t->dropColumn('origin'));
        Schema::table('contract_billing_milestones', fn (Blueprint $t) => $t->dropColumn('description'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['billing_monthly_cents', 'billing_monthly_description', 'billing_start_date', 'billing_auto', 'billing_mode']));
    }
};
