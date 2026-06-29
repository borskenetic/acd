<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendanceAlertState extends Model
{
    protected $fillable = [
        'student_id',
        'late_streak_notified',
        'absent_streak_notified',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
