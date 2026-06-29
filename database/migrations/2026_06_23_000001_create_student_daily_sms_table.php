<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_daily_sms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->boolean('arrival_sent')->default(false);
            $table->boolean('departure_sent')->default(false);
            $table->timestamps();

            $table->unique(['student_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_daily_sms');
    }
};
