<?php

use App\Enums\EducationalLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('students')) {
            DB::table('students')
                ->whereIn('year', EducationalLevel::legacyKinderYearLabels())
                ->update(['year' => 'Kinder']);
        }

        if (Schema::hasTable('pending_students')) {
            DB::table('pending_students')
                ->whereIn('year', EducationalLevel::legacyKinderYearLabels())
                ->update(['year' => 'Kinder']);
        }

        if (Schema::hasTable('grade_sections')) {
            $legacy = EducationalLevel::legacyKinderYearLabels();
            $rows = DB::table('grade_sections')
                ->whereIn('grade_level', $legacy)
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $exists = DB::table('grade_sections')
                    ->where('grade_level', 'Kinder')
                    ->where('section', $row->section)
                    ->exists();

                if ($exists) {
                    DB::table('grade_sections')->where('id', $row->id)->delete();
                } else {
                    DB::table('grade_sections')->where('id', $row->id)->update([
                        'grade_level' => 'Kinder',
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Irreversible merge.
    }
};
