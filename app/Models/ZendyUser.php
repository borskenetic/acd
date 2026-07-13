<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class ZendyUser extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'zendy_users';

    protected $fillable = [
        'fname',
        'lname',
        'email',
        'password',
        'role',
        'course',
        'department',
        'campus',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public static function registerableRoleOptions(): array
    {
        return [
            'ntp_admin' => 'NTP/ADMIN',
            'faculty_full_time' => 'Faculty-full time',
            'faculty_part_time' => 'Faculty-part time',
            'student' => 'Student',
            'academic_researcher' => 'Academic researcher',
            'corporate_researcher' => 'Corporate researcher',
            'lecturer' => 'Lecturer',
            'librarian' => 'Librarian',
            'master_undergraduate_student' => 'Master or Undergraduate student',
            'other_professional' => 'Other professional',
            'phd_student' => 'PhD student',
            'publishing_professional' => 'Publishing professional',
        ];
    }

    /** @return list<string> */
    public static function studentRoleKeys(): array
    {
        return [
            'student',
            'master_undergraduate_student',
            'phd_student',
        ];
    }

    public static function roleOptions(): array
    {
        return array_merge(self::registerableRoleOptions(), [
            'admin' => 'Administrator',
            'faculty' => 'Faculty',
            'staff' => 'Staff',
        ]);
    }

    public static function isStudentRole(?string $role): bool
    {
        return in_array($role, self::studentRoleKeys(), true);
    }
}
