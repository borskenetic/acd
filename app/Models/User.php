<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
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

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    
    public function student()
    {
        return $this->hasOne(Student::class);
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

    /** Roles that may use the Zendy research portal. */
    public static function zendyRoles(): array
    {
        return ['admin', 'staff', 'librarian', 'faculty', 'student'];
    }
}
