<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->json('scopes');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['business_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
