<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sf2Report extends Model
{
    protected $fillable = [
        'user_id',
        'school_id',
        'school_name',
        'school_year',
        'semester',
        'division',
        'region',
        'report_month',
        'report_year',
        'grade_level',
        'section',
        'track_and_strand',
        'tvl_courses',
        'school_days',
        'summary',
        'teacher_name',
        'school_head_name',
    ];

    protected function casts(): array
    {
        return [
            'school_days' => 'array',
            'summary' => 'array',
            'report_month' => 'integer',
            'report_year' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Sf2ReportStudent::class)->orderBy('sort_order');
    }

    public function reportMonthLabel(): string
    {
        $names = config('sf2.month_names', []);

        return $names[$this->report_month] ?? (string) $this->report_month;
    }

    public function titleLabel(): string
    {
        return sprintf(
            '%s — %s %s (%d)',
            $this->section,
            $this->grade_level,
            $this->reportMonthLabel(),
            $this->report_year
        );
    }

    /** True when this report should export the SF2-SHS workbook (Grade 11–12). */
    public function usesShsTemplate(): bool
    {
        $level = trim((string) $this->grade_level);
        $shs = config('sf2.shs_grades', ['Grade 11', 'Grade 12']);

        foreach ($shs as $option) {
            if (strcasecmp($level, (string) $option) === 0) {
                return true;
            }
        }

        return (bool) preg_match('/\b(?:grade\s*)?1[12]\b/i', $level);
    }
}
