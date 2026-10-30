<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_outbox', function (Blueprint $table) {
            $table->char('tracking_hash', 64)->nullable()->unique();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);
        });

        Schema::table('document_access_events', function (Blueprint $table) {
            $table->unsignedBigInteger('email_outbox_id')->nullable();
            $table->string('detail', 60)->nullable();
            $table->index(['business_id', 'contract_version_id']);
        });
    }

    public function down(): void
    {
        Schema::table('document_access_events', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'contract_version_id']);
            $table->dropColumn(['email_outbox_id', 'detail']);
        });
        Schema::table('email_outbox', function (Blueprint $table) {
            $table->dropUnique(['tracking_hash']);
            $table->dropColumn(['tracking_hash', 'opened_at', 'last_opened_at', 'open_count', 'clicked_at', 'click_count']);
        });
    }
};
