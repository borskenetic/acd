<?php

namespace App\Models;

use App\Enums\EducationalLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Student extends Model
{
    protected $fillable = [
        'record_id',
        'student_id',
        'lrn',
        'firstname',
        'lastname',
        'course',
        'mobile_number',
        'year',
        'section',
        'sex',
        'educational_level',
        'profile_picture',
        'face_descriptor',
        'face_enrolled_at',
        'qrcode',
        'rfid',
        'birth_date',
        'blood_type',
        'emergency_person',
        'emergency_relationship',
        'emergency_number',
        'emergency_address',
        'student_signature',
        'middle_initial',
        'role_id',
        'normalized_name',
        'address',
    ];

    protected function casts(): array
    {
        return [
            'educational_level' => EducationalLevel::class,
            'face_descriptor' => 'array',
            'face_enrolled_at' => 'datetime',
        ];
    }

    protected static function booted()
    {
        static::creating(function ($student) {
            if (empty($student->qrcode)) {
                // Example: encode a UUID or ID
                $student->qrcode = Str::uuid()->toString();
            }
        });
    }
    
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
    
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasFaceEnrolled(): bool
    {
        return is_array($this->face_descriptor) && count($this->face_descriptor) === (int) config('face.descriptor_length', 128);
    }
}
