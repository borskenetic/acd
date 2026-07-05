<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
            $table->foreignId('gate_device_id')->nullable()->after('client_uuid')->constrained('gate_devices')->nullOnDelete();
            $table->string('source', 32)->default('web')->after('gate_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gate_device_id');
            $table->dropColumn(['client_uuid', 'source']);
        });
    }
};
