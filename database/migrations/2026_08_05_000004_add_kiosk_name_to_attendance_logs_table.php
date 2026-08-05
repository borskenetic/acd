<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // Snapshot of where the scan happened (survives device rename/delete).
            $table->string('kiosk_name', 120)->nullable()->after('section');
            $table->index('kiosk_name');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['kiosk_name']);
            $table->dropColumn('kiosk_name');
        });
    }
};
