<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_records', function (Blueprint $table) {
            $table->text('search_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_records', fn (Blueprint $table) => $table->dropColumn('search_text'));
    }
};
