<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sf2_reports', function (Blueprint $table) {
            $table->string('semester', 64)->nullable()->after('school_year');
            $table->string('division', 120)->nullable()->after('semester');
            $table->string('region', 32)->nullable()->after('division');
            $table->string('track_and_strand', 255)->nullable()->after('section');
            $table->string('tvl_courses', 255)->nullable()->after('track_and_strand');
        });
    }

    public function down(): void
    {
        Schema::table('sf2_reports', function (Blueprint $table) {
            $table->dropColumn([
                'semester',
                'division',
                'region',
                'track_and_strand',
                'tvl_courses',
            ]);
        });
    }
};
