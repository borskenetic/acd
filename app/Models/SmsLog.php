<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'to_number',
        'message',
        'type',
        'status',
        'http_status',
        'error',
        'student_id',
        'user_id',
        'recipient_label',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'http_status' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            'blast' => 'SMS Blast',
            'arrival' => 'Gate arrival',
            'departure' => 'Gate departure',
            'morning_in' => 'Morning in',
            'lunch_out' => 'Lunch out',
            'half_day_out' => 'Half-day out',
            'afternoon_in' => 'Afternoon in',
            'eod_out' => 'End of day out',
            'missed_eod' => 'Missed EOD',
            'consecutive_late' => 'Consecutive late',
            'consecutive_absent' => 'Consecutive absent',
            'gate' => 'Gate SMS',
            'unknown' => 'Other',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? $this->type;
    }
}
