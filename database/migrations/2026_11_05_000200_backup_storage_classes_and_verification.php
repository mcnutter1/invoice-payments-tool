<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_snapshots', function (Blueprint $table) {
            $table->string('storage_class', 30)->nullable();
            // Compressed key list of the latest point, so hourly deltas never need to read from (cold) S3 storage.
            $table->longText('manifest')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verify_error')->nullable();
        });
        Schema::table('backup_files', function (Blueprint $table) {
            $table->string('storage_class', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_files', fn (Blueprint $table) => $table->dropColumn('storage_class'));
        Schema::table('backup_snapshots', fn (Blueprint $table) => $table->dropColumn(['storage_class', 'manifest', 'verified_at', 'verify_error']));
    }
};
