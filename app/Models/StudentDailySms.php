<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDailySms extends Model
{
    protected $table = 'student_daily_sms';

    protected $fillable = [
        'student_id',
        'log_date',
        'arrival_sent',
        'departure_sent',
    ];

    protected $casts = [
        'log_date' => 'date',
        'arrival_sent' => 'boolean',
        'departure_sent' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
