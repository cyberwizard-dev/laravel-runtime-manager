<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_mail_log', function (Blueprint $table) {
            $table->id();
            $table->string('mailer')->nullable();
            $table->string('to_domains');                 // recipient domains, not full addresses
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->string('subject')->nullable();
            $table->string('status', 12)->default('sent'); // sent (transport handoff ok)
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_mail_log');
    }
};
