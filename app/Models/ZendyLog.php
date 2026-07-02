<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZendyLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'zendy_user_id',
        'actor_role',
        'action',
        'first_name',
        'last_name',
        'course',
        'department',
        'campus',
        'email',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(ZendyUser::class, 'zendy_user_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'zendy_launch' => 'Launch',
            'go_to_zendy' => 'Direct link',
            'zendy_return' => 'Return',
            'zendy_tab_close' => 'Tab closed',
            'zendy_form_submission' => 'Form',
            'zendy_sso' => 'Sign-on',
            'zendy_sso_unavailable' => 'Sign-on unavailable',
            default => str_replace('_', ' ', ucfirst((string) $this->action)),
        };
    }

    public function durationSeconds(): ?int
    {
        $seconds = $this->metadata['estimated_duration_seconds'] ?? null;

        return is_numeric($seconds) ? (int) $seconds : null;
    }

    public function durationLabel(): ?string
    {
        return \App\Services\ZendyTrackingService::formatDuration($this->durationSeconds());
    }
}
