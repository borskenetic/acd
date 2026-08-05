<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolCalendarDay extends Model
{
    public const TYPE_SCHOOL_DAY = 'school_day';

    public const TYPE_HOLIDAY = 'holiday';

    public const TYPE_OTHERWISE = 'otherwise';

    protected $fillable = [
        'date',
        'type',
        'label',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_SCHOOL_DAY => 'School day',
            self::TYPE_HOLIDAY => 'Holiday',
            self::TYPE_OTHERWISE => 'Otherwise (special / non-class day)',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeOptions()[$this->type] ?? $this->type;
    }

    /** Counts toward attendance / SF2 school days. */
    public function countsAsSchoolDay(): bool
    {
        return $this->type === self::TYPE_SCHOOL_DAY;
    }
}
