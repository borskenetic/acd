<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GateDevice extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'last_seen_at',
        'last_sync_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    /** @return array{device: self, plain_token: string} */
    public static function issue(string $name): array
    {
        $plain = 'gate_'.Str::random(48);

        $device = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'is_active' => true,
        ]);

        return ['device' => $device, 'plain_token' => $plain];
    }

    /** Rotate access token (plain returned once — for pairing kiosks / gate-terminal). */
    public function reissueToken(): string
    {
        $plain = 'gate_'.Str::random(48);
        $this->forceFill(['token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }

    public static function findByToken(string $plainToken): ?self
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return null;
        }

        $hash = hash('sha256', $plainToken);

        return static::query()
            ->where('token_hash', $hash)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve active kiosk/device from request (X-Gate-Token, X-Kiosk-Token, body, or Bearer).
     */
    public static function resolveFromRequest(\Illuminate\Http\Request $request): ?self
    {
        $token = $request->header('X-Gate-Token')
            ?: $request->header('X-Kiosk-Token')
            ?: $request->input('kiosk_token')
            ?: $request->input('device_token')
            ?: $request->bearerToken();

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $device = static::findByToken($token);
        if ($device) {
            $device->touchSeen();
        }

        return $device;
    }

    public function touchSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->save();
    }

    public function touchSynced(): void
    {
        $this->forceFill([
            'last_seen_at' => now(),
            'last_sync_at' => now(),
        ])->save();
    }
}
