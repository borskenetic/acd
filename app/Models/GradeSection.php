<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class GradeSection extends Model
{
    protected $fillable = [
        'grade_level',
        'strand',
        'section',
    ];

    /** @return list<string> */
    public static function seniorHighGrades(): array
    {
        return config('patron.senior_high_grades', ['Grade 11', 'Grade 12']);
    }

    public static function isSeniorHighGrade(string $grade): bool
    {
        return in_array($grade, self::seniorHighGrades(), true);
    }

    /** @return list<string> */
    public static function knownStrands(): array
    {
        return SchoolStrand::orderedNames();
    }

    /**
     * Ensure school-setup grade_sections includes every grade/section present on student rows.
     * Import-only values otherwise never appear in setup-driven dropdowns.
     *
     * @return int Number of newly created rows
     */
    public static function syncFromStudents(): int
    {
        if (! Schema::hasTable('grade_sections') || ! Schema::hasTable('students')) {
            return 0;
        }

        $allowed = config('sf2.grade_levels', []);
        $senior = self::seniorHighGrades();
        $created = 0;

        $pairs = Student::query()
            ->whereNotNull('year')
            ->where('year', '!=', '')
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->select('year', 'section', 'course')
            ->distinct()
            ->get();

        $mapper = app(\App\Services\Sf2AttendanceLogMapper::class);

        foreach ($pairs as $row) {
            $grade = $mapper->canonicalizeYear(trim((string) $row->year)) ?? trim((string) $row->year);
            $section = trim((string) $row->section);
            if ($grade === '' || $section === '') {
                continue;
            }

            if ($allowed !== [] && ! in_array($grade, $allowed, true)) {
                continue;
            }

            $strand = '';
            if (self::isSeniorHighGrade($grade)) {
                $strand = trim((string) ($row->course ?? ''));
                if ($strand === '') {
                    // Senior sections without strand stay on the student only until strand is set.
                    continue;
                }
            }

            $model = self::firstOrCreate([
                'grade_level' => $grade,
                'strand' => $strand,
                'section' => $section,
            ]);

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /** @return array<string, list<string>> K–10: grade => sections (no strand) */
    public static function groupedByGrade(): array
    {
        $allowed = config('sf2.grade_levels', []);
        $order = array_flip($allowed);
        $grouped = [];

        static::query()
            ->where('strand', '')
            ->orderBy('grade_level')
            ->orderBy('section')
            ->get()
            ->each(function (self $row) use (&$grouped) {
                $grouped[$row->grade_level][] = $row->section;
            });

        uksort($grouped, fn (string $a, string $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

        return $grouped;
    }

    /** @return array<string, array<string, list<string>>> G11–12: grade => strand => sections */
    public static function groupedByGradeAndStrand(): array
    {
        $grouped = [];

        static::query()
            ->whereIn('grade_level', self::seniorHighGrades())
            ->where('strand', '!=', '')
            ->orderBy('grade_level')
            ->orderBy('strand')
            ->orderBy('section')
            ->get()
            ->each(function (self $row) use (&$grouped) {
                $grouped[$row->grade_level][$row->strand][] = $row->section;
            });

        return $grouped;
    }

    /** @return array{
     *   sectionsByGrade: array<string, list<string>>,
     *   sectionsByGradeStrand: array<string, array<string, list<string>>>,
     *   strands: list<string>,
     *   seniorHighGrades: list<string>
     * }
     */
    public static function registrationData(): array
    {
        return [
            'sectionsByGrade' => self::groupedByGrade(),
            'sectionsByGradeStrand' => self::groupedByGradeAndStrand(),
            'strands' => self::knownStrands(),
            'seniorHighGrades' => self::seniorHighGrades(),
        ];
    }
}
