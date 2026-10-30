<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('renewed_from_id')->nullable();
            $table->timestamp('ended_at')->nullable(); // term ran out (status 'expired' after execution)
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['renewed_from_id', 'ended_at']));
    }
};
