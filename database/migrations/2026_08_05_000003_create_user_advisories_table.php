<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_advisories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('year', 64);
            $table->string('section', 64);
            /** adviser = full roster control; subject_teacher = view only */
            $table->string('access_level', 32)->default('adviser');
            $table->timestamps();

            $table->unique(['user_id', 'year', 'section']);
            $table->index(['year', 'section']);
        });

        if (Schema::hasColumns('users', ['advisory_year', 'advisory_section'])) {
            $rows = DB::table('users')
                ->where('role', 'faculty')
                ->whereNotNull('advisory_year')
                ->where('advisory_year', '!=', '')
                ->whereNotNull('advisory_section')
                ->where('advisory_section', '!=', '')
                ->get(['id', 'advisory_year', 'advisory_section']);

            $now = now();
            foreach ($rows as $row) {
                DB::table('user_advisories')->insertOrIgnore([
                    'user_id' => $row->id,
                    'year' => $row->advisory_year,
                    'section' => $row->advisory_section,
                    'access_level' => 'adviser',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_advisories');
    }
};
