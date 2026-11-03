<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['audit_events', 'document_access_events'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('location', 120)->nullable();
                $table->string('network', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['audit_events', 'document_access_events'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['location', 'network']));
        }
    }
};
