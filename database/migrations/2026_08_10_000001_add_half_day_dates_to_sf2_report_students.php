<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sf2_report_students', function (Blueprint $table) {
            $table->json('half_day_dates')->nullable()->after('tardy_dates');
        });
    }

    public function down(): void
    {
        Schema::table('sf2_report_students', function (Blueprint $table) {
            $table->dropColumn('half_day_dates');
        });
    }
};
