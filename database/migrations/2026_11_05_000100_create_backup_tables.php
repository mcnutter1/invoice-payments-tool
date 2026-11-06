<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('backups_enabled')->default(false);
        });

        Schema::create('backup_snapshots', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // full | delta
            $table->unsignedBigInteger('base_snapshot_id')->nullable(); // the full this chain starts from
            $table->unsignedBigInteger('parent_snapshot_id')->nullable();
            $table->string('status', 20)->default('running');
            $table->string('trigger', 20)->default('schedule'); // schedule | manual | pre_restore
            $table->timestamp('since_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('object_key')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deleted_count')->default(0);
            $table->unsignedInteger('files_uploaded')->default(0);
            $table->json('table_stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'started_at']);
        });

        Schema::create('backup_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('backup_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('business_id');
            $table->string('table_name', 64);
            $table->string('record_key', 64);
            $table->string('action', 10); // created | updated | deleted
            $table->string('label')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->index(['business_id', 'table_name', 'record_key']);
            $table->index(['business_id', 'snapshot_id']);
        });

        // One row per backed-up record, for finding things by name without opening each backup.
        Schema::create('backup_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('table_name', 64);
            $table->string('record_key', 64);
            $table->string('label')->nullable();
            $table->unsignedInteger('versions')->default(0);
            $table->unsignedBigInteger('last_snapshot_id')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->boolean('deleted')->default(false);
            $table->unique(['business_id', 'table_name', 'record_key']);
            $table->index(['business_id', 'label']);
        });

        // Content-addressed private document files already uploaded (deduplicated per business).
        Schema::create('backup_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('document_id');
            $table->char('sha256', 64);
            $table->string('mime', 100)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('object_key');
            $table->timestamp('created_at')->nullable();
            $table->unique(['business_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_files');
        Schema::dropIfExists('backup_records');
        Schema::dropIfExists('backup_changes');
        Schema::dropIfExists('backup_snapshots');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('backups_enabled'));
        Schema::dropIfExists('platform_settings');
    }
};
