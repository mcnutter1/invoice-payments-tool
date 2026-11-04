<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_access_events', function (Blueprint $table) {
            $table->string('automation_reason', 120)->nullable();
        });
        Schema::table('email_outbox', function (Blueprint $table) {
            $table->timestamp('scanned_at')->nullable();
            $table->unsignedInteger('scan_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('email_outbox', fn (Blueprint $table) => $table->dropColumn(['scanned_at', 'scan_count']));
        Schema::table('document_access_events', fn (Blueprint $table) => $table->dropColumn('automation_reason'));
    }
};
