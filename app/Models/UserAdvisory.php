<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAdvisory extends Model
{
    public const LEVEL_ADVISER = 'adviser';

    public const LEVEL_SUBJECT = 'subject_teacher';

    protected $fillable = [
        'user_id',
        'year',
        'section',
        'access_level',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAdviser(): bool
    {
        return $this->access_level === self::LEVEL_ADVISER;
    }

    public function isSubjectTeacher(): bool
    {
        return $this->access_level === self::LEVEL_SUBJECT;
    }

    public function label(): string
    {
        $role = $this->isAdviser() ? 'Adviser' : 'Subject teacher';

        return trim($this->year.' · '.$this->section).' ('.$role.')';
    }

    /** @return array<string, string> */
    public static function accessLevelOptions(): array
    {
        return [
            self::LEVEL_ADVISER => 'Class adviser (add / edit / delete students)',
            self::LEVEL_SUBJECT => 'Subject teacher (view only)',
        ];
    }
}
