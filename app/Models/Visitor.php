<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Visitor extends Model
{
    public const DEFAULT_GUARD_QR = 'V-GUARDPASS';

    protected $fillable = [
        'firstname',
        'lastname',
        'organization',
        'purpose',
        'mobile_number',
        'email',
        'qrcode',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(VisitorLog::class);
    }

    public function fullName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    public static function allocateQrCode(): string
    {
        return DB::transaction(function () {
            $last = static::query()->lockForUpdate()->orderByDesc('id')->first();
            $nextNumber = 1;

            if ($last && $last->qrcode && str_starts_with((string) $last->qrcode, 'V-')) {
                $nextNumber = (int) substr($last->qrcode, 2) + 1;
            }

            return 'V-'.str_pad((string) $nextNumber, 8, '0', STR_PAD_LEFT);
        });
    }

    /** Reusable QR for visitors without a phone — one record, many gate scans. */
    public static function defaultGuardPass(): self
    {
        return static::firstOrCreate(
            ['qrcode' => self::DEFAULT_GUARD_QR],
            [
                'firstname' => 'Walk-in',
                'lastname' => 'Visitor',
                'purpose' => 'Reusable guardhouse pass',
            ]
        );
    }
}
