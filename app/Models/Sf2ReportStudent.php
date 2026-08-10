<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sf2ReportStudent extends Model
{
    protected $fillable = [
        'sf2_report_id',
        'sort_order',
        'sex',
        'last_name',
        'first_name',
        'middle_name',
        'remarks',
        'absent_dates',
        'tardy_dates',
        'half_day_dates',
    ];

    protected function casts(): array
    {
        return [
            'absent_dates' => 'array',
            'tardy_dates' => 'array',
            'half_day_dates' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Sf2Report::class, 'sf2_report_id');
    }

    public function formattedName(): string
    {
        $middle = trim((string) $this->middle_name);

        return trim($this->last_name.', '.$this->first_name.($middle !== '' ? ' '.$middle : ''));
    }

    public function isMale(): bool
    {
        return strtolower($this->sex) === 'male';
    }
}
