<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('w9_settings')->nullable();
            $table->text('w9_tin_encrypted')->nullable();
        });
        Schema::table('public_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedSmallInteger('max_uses')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_tokens', fn (Blueprint $table) => $table->dropColumn(['client_id', 'max_uses']));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn(['w9_settings', 'w9_tin_encrypted']));
    }
};
