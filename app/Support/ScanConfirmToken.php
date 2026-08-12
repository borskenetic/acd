<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Short-lived tokens proving a kiosk already resolved a student/visitor
 * (via QR/RFID/face) before writing attendance — blocks forge-by-id POSTs.
 */
class ScanConfirmToken
{
    public static function issue(string $type, int $id, int $ttlSeconds = 180): string
    {
        return Crypt::encrypt([
            'type' => $type,
            'id' => $id,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
        ]);
    }

    public static function assertValid(string $token, string $type, int $id): void
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (DecryptException) {
            throw new \InvalidArgumentException('Scan confirmation expired. Please scan again.');
        }

        if (! is_array($payload)
            || ($payload['type'] ?? null) !== $type
            || (int) ($payload['id'] ?? 0) !== $id
            || ! isset($payload['exp'])
            || (int) $payload['exp'] < now()->getTimestamp()
        ) {
            throw new \InvalidArgumentException('Scan confirmation expired. Please scan again.');
        }
    }
}
