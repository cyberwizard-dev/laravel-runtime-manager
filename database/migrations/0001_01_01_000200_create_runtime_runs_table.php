<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // success | partial | failed | skipped
            $table->string('status', 16)->default('success');
            $table->string('trigger', 16)->default('cli'); // cli | http
            $table->json('services')->nullable();           // per-service outcome + timings
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('started_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_runs');
    }
};
