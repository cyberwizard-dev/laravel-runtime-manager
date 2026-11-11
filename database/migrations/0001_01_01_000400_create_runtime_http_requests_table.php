<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_http_requests', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('host');
            $table->string('path', 512)->nullable();     // query string intentionally stripped
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('ok')->default(false);
            $table->string('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('host');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_http_requests');
    }
};
