<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $fillable = [
        'student_id',
        'status',
        'section',
        'scanned_at',
        'client_uuid',
        'gate_device_id',
        'source',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function gateDevice()
    {
        return $this->belongsTo(GateDevice::class);
    }
}
