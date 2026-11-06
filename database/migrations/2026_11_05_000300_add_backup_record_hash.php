<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_records', function (Blueprint $table) {
            // Content hash (ignoring timestamps) so a touched-but-unchanged row isn't recorded as a new version.
            $table->char('row_hash', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_records', fn (Blueprint $table) => $table->dropColumn('row_hash'));
    }
};
