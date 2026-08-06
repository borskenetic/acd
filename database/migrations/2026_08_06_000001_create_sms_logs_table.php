<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_number', 32)->nullable();
            $table->text('message');
            $table->string('type', 64)->default('unknown');
            $table->string('status', 16); // success | failed | skipped
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_label')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('status');
            $table->index('type');
            $table->index('to_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
