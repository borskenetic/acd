<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'advisory_year',
        'advisory_section',
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

    public function advisories(): HasMany
    {
        return $this->hasMany(UserAdvisory::class);
    }

    public function isFaculty(): bool
    {
        return $this->role === 'faculty';
    }

    public function hasAdvisoryClass(): bool
    {
        if ($this->advisories()->exists()) {
            return true;
        }

        return filled($this->advisory_year) && filled($this->advisory_section);
    }

    public function advisoryLabel(): string
    {
        return \App\Support\AdvisoryScope::advisoryLabels($this);
    }

    public function fullName(): string
    {
        return trim(($this->fname ?? '').' '.($this->lname ?? '')) ?: (string) $this->email;
    }

    public static function roleOptions(): array
    {
        return [
            'admin' => 'Administrator',
            'staff' => 'Staff',
            'faculty' => 'Faculty (adviser / subject teacher)',
            'student' => 'Student',
        ];
    }
}
