<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide tax parameters; built-in defaults live in code and rows here override them per jurisdiction-year.
        Schema::create('tax_tables', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 10); // US | NJ | NY | …
            $table->unsignedSmallInteger('year');
            $table->json('data');
            $table->string('source', 20); // irs.gov | ssa.gov | feed | manual
            $table->string('source_url')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['jurisdiction', 'year']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->json('tax_profile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('tax_profile'));
        Schema::dropIfExists('tax_tables');
    }
};
