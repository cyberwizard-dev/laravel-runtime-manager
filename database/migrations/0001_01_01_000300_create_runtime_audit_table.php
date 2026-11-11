<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_audit', function (Blueprint $table) {
            $table->id();
            $table->string('actor')->default('dashboard');
            $table->string('action');                 // e.g. queue.retry, queue.flush, migrate.run
            $table->string('target')->nullable();     // id / queue name / command
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_audit');
    }
};
