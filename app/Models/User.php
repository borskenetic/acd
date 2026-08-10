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

    /** Full platform superadmin (no grade keyhole). */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Superadmin, SHS Admin, or K–10 Admin. */
    public function isPlatformAdmin(): bool
    {
        return in_array($this->role, ['admin', 'shs_admin', 'k10_admin'], true);
    }

    /** School ops portal (admin tiers + staff). */
    public function isSchoolOps(): bool
    {
        return in_array($this->role, ['admin', 'staff', 'shs_admin', 'k10_admin'], true);
    }

    public function isBandAdmin(): bool
    {
        return in_array($this->role, ['shs_admin', 'k10_admin'], true);
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

    /**
     * Grade levels this user may access. Null = whole school (superadmin / staff).
     *
     * @return list<string>|null
     */
    public function allowedGradeLevels(): ?array
    {
        return match ($this->role) {
            'admin', 'staff' => null,
            'shs_admin' => array_values(config('patron.senior_high_grades', ['Grade 11', 'Grade 12'])),
            'k10_admin' => array_values(array_merge(
                config('patron.year_options.grade_school', []),
                config('patron.year_options.high_school_junior', []),
            )),
            default => [],
        };
    }

    public function canAccessGradeLevel(?string $year): bool
    {
        $allowed = $this->allowedGradeLevels();
        if ($allowed === null) {
            return true;
        }

        $year = trim((string) $year);
        if ($year === '' || $allowed === []) {
            return false;
        }

        $aliases = self::gradeLevelAliases($allowed);

        return in_array($year, $aliases, true)
            || in_array(self::canonicalizeGradeLabel($year), array_map([self::class, 'canonicalizeGradeLabel'], $allowed), true);
    }

    /**
     * Expand canonical grades to stored variants (e.g. Grade 11 → G11, 11).
     *
     * @param  list<string>  $grades
     * @return list<string>
     */
    public static function gradeLevelAliases(array $grades): array
    {
        $out = [];
        foreach ($grades as $grade) {
            $out[] = $grade;
            $out[] = self::canonicalizeGradeLabel($grade);
            if (preg_match('/^Grade\s+(1[0-2]|[1-9])$/i', $grade, $m)) {
                $n = $m[1];
                $out = array_merge($out, [(string) $n, 'G'.$n, 'g'.$n, 'Grade'.$n, 'GRADE '.$n]);
            }
            if (strcasecmp($grade, 'Kinder') === 0) {
                $out = array_merge($out, ['Kindergarten', 'K', 'K1', 'K2', 'Kinder 1', 'Kinder 2']);
            }
        }

        return array_values(array_unique(array_filter($out, fn ($v) => $v !== '')));
    }

    public static function canonicalizeGradeLabel(string $raw): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        if ($value === '') {
            return '';
        }

        if (preg_match('/\bkinder(?:garten)?\s*([12])?\b/i', $value) || preg_match('/^k\s*([12])?$/i', $value)) {
            return 'Kinder';
        }

        if (preg_match('/^(?:grade|g)?\s*(1[0-2]|[1-9])$/i', $value, $m)
            || preg_match('/\bgrade\s*(1[0-2]|[1-9])\b/i', $value, $m)) {
            return 'Grade '.$m[1];
        }

        return $value;
    }

    public static function roleOptions(): array
    {
        return [
            'admin' => 'Administrator (superadmin)',
            'shs_admin' => 'SHS Admin (Grade 11–12 only)',
            'k10_admin' => 'K–10 Admin (Kinder–Grade 10 only)',
            'staff' => 'Staff',
            'faculty' => 'Faculty (adviser / subject teacher)',
            'student' => 'Student',
        ];
    }

    /** Roles assignable when creating accounts (superadmin UI). */
    public static function assignableRoleOptions(): array
    {
        return [
            'admin' => 'Administrator (superadmin)',
            'shs_admin' => 'SHS Admin (Grade 11–12 only)',
            'k10_admin' => 'K–10 Admin (Kinder–Grade 10 only)',
            'staff' => 'Staff',
            'faculty' => 'Faculty (adviser / subject teacher)',
        ];
    }
}
