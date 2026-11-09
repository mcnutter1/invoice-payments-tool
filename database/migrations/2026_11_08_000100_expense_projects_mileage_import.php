<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('open'); // open | closed
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'client_id']);
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('source', 20)->default('manual'); // manual | import | mileage | migration
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_id', 100)->nullable();
            $table->json('import_meta')->nullable();
            $table->unsignedSmallInteger('revision')->default(0);
            $table->timestamp('edited_at')->nullable();
            $table->index(['business_id', 'project_id']);
            $table->index(['business_id', 'external_id']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            // Remembered CSV category/account → chart account choices for expense imports.
            $table->json('expense_import_mappings')->nullable();
        });

        // IRS standard mileage rates (platform-wide), in tenths of a cent per mile.
        Schema::create('mileage_rates', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('business_rate_millis');
            $table->string('source', 40)->default('seed'); // seed | irs.gov | manual
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique('effective_from');
        });

        Schema::create('mileage_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->date('trip_date');
            $table->string('from_location', 200)->nullable();
            $table->string('to_location', 200)->nullable();
            $table->string('purpose', 255);
            $table->decimal('miles', 9, 1);
            $table->boolean('round_trip')->default(false);
            $table->string('vehicle', 80)->nullable();
            $table->unsignedInteger('rate_millis');
            $table->unsignedBigInteger('mileage_rate_id')->nullable();
            $table->bigInteger('amount_cents');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['business_id', 'trip_date']);
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
            $table->foreign(['business_id', 'project_id'])->references(['business_id', 'id'])->on('projects');
        });

        $seed = [
            ['2019-01-01', 580], ['2020-01-01', 575], ['2021-01-01', 560], ['2022-01-01', 585], ['2022-07-01', 625],
            ['2023-01-01', 655], ['2024-01-01', 670], ['2025-01-01', 700],
        ];
        foreach ($seed as [$from, $rate]) {
            DB::table('mileage_rates')->insert(['effective_from' => $from, 'business_rate_millis' => $rate, 'source' => 'seed',
                'note' => 'IRS standard mileage rate (business)', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mileage_trips');
        Schema::dropIfExists('mileage_rates');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('expense_import_mappings'));
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'project_id']);
            $table->dropIndex(['business_id', 'external_id']);
            $table->dropColumn(['project_id', 'source', 'import_batch_id', 'external_id', 'import_meta', 'revision', 'edited_at']);
        });
        Schema::dropIfExists('projects');
    }
};
