<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $fillable = [
        'student_id',
        'status',
        'section',
        'kiosk_name',
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

    /** Display label for where the scan happened. */
    public function kioskLabel(): string
    {
        if (filled($this->kiosk_name)) {
            return (string) $this->kiosk_name;
        }

        if ($this->relationLoaded('gateDevice') && $this->gateDevice) {
            return (string) $this->gateDevice->name;
        }

        return match ((string) $this->source) {
            'gate_sync' => 'Gate terminal (offline)',
            'friday_auto' => 'Friday online (auto)',
            'auto_eod_out', 'auto_lunch_out', 'auto_afternoon_in' => 'System autofill',
            'streak_demo' => 'Demo seed',
            'web' => 'Unnamed kiosk',
            'web_kiosk' => 'Named kiosk',
            default => filled($this->source) ? (string) $this->source : '—',
        };
    }
}
