<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_state', function (Blueprint $table) {
            $table->id();
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('last_tick_duration_ms')->nullable();
            $table->string('current_phase')->nullable();
            $table->string('php_sapi', 32)->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('total_ticks')->default(0);
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_state');
    }
};
