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

    public static function roleOptions(): array
    {
        return [
            'student' => 'Student',
            'faculty' => 'Faculty',
            'staff' => 'Staff',
            'librarian' => 'Librarian',
            'admin' => 'Administrator',
        ];
    }
}
