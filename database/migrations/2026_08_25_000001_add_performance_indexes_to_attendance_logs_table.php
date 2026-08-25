<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index('scanned_at', 'attendance_logs_scanned_at_index');
            $table->index(['student_id', 'status', 'scanned_at'], 'attendance_logs_student_status_scanned_index');
            $table->index(['status', 'scanned_at'], 'attendance_logs_status_scanned_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('attendance_logs_scanned_at_index');
            $table->dropIndex('attendance_logs_student_status_scanned_index');
            $table->dropIndex('attendance_logs_status_scanned_index');
        });
    }
};
