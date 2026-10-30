<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('require_email_code')->default(true);
        });
        Schema::table('contract_recipients', function (Blueprint $table) {
            $table->string('verification_method', 30)->nullable();
        });
        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('signature_document_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropColumn('signature_document_id'));
        Schema::table('contract_recipients', fn (Blueprint $t) => $t->dropColumn('verification_method'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('require_email_code'));
    }
};
