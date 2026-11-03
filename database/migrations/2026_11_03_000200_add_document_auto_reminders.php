<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // null = follow the business reminder settings
        foreach (['invoices', 'contracts'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->boolean('auto_reminders')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'contracts'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('auto_reminders'));
        }
    }
};
