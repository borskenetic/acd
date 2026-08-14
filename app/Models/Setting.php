<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const KEY_LOGOUT_FEEDBACK = 'logout_feedback_enabled';

    public const KEY_SECTION_PICKER = 'section_picker_enabled';

    public const KEY_ATTENDANCE_SECTIONS = 'attendance_sections';

    public const KEY_SCAN_SMS = 'scan_sms';

    public const KEY_SCAN_SMS_ARRIVAL = 'scan_sms_arrival';

    public const KEY_SCAN_SMS_DEPARTURE = 'scan_sms_departure';

    public const KEY_SCAN_SMS_MORNING_IN = 'scan_sms_morning_in';

    public const KEY_SCAN_SMS_LUNCH_OUT = 'scan_sms_lunch_out';

    public const KEY_SCAN_SMS_AFTERNOON_IN = 'scan_sms_afternoon_in';

    public const KEY_SCAN_SMS_EOD_OUT = 'scan_sms_eod_out';

    public const KEY_SCAN_SMS_MISSED_EOD = 'scan_sms_missed_eod';

    public const KEY_SMS_CONSECUTIVE_LATE = 'sms_consecutive_late';

    public const KEY_SMS_CONSECUTIVE_ABSENT = 'sms_consecutive_absent';

    public const KEY_SMS_CONSECUTIVE_LATE_ENABLED = 'sms_consecutive_late_enabled';

    public const KEY_SMS_CONSECUTIVE_ABSENT_ENABLED = 'sms_consecutive_absent_enabled';

    public const KEY_ATTENDANCE_POLICY = 'attendance_policy';

    public const KEY_SMS_SIM_LOAD = 'sms_sim_load';

    public const DEFAULT_ATTENDANCE_SECTIONS = [
        'Circulation Section',
        'Reference Section',
        'Serials Section',
        'Filipiniana Section',
        'Discussion Room',
        'Audio Visual Room',
        'Learning Commons',
        'Biblionook',
    ];

    protected $fillable = ['key', 'value'];

    public static function logoutFeedbackEnabled(): bool
    {
        $value = static::where('key', self::KEY_LOGOUT_FEEDBACK)->value('value');

        if ($value === null) {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function setLogoutFeedbackEnabled(bool $enabled): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_LOGOUT_FEEDBACK],
            ['value' => $enabled ? '1' : '0']
        );
    }

    public static function sectionPickerEnabled(): bool
    {
        $value = static::where('key', self::KEY_SECTION_PICKER)->value('value');

        if ($value === null) {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function setSectionPickerEnabled(bool $enabled): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_SECTION_PICKER],
            ['value' => $enabled ? '1' : '0']
        );
    }

    /** @return list<string> */
    public static function attendanceSections(): array
    {
        $raw = static::where('key', self::KEY_ATTENDANCE_SECTIONS)->value('value');

        if ($raw === null) {
            return self::DEFAULT_ATTENDANCE_SECTIONS;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return self::DEFAULT_ATTENDANCE_SECTIONS;
        }

        $sections = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $decoded
        ))));

        return $sections !== [] ? $sections : self::DEFAULT_ATTENDANCE_SECTIONS;
    }

    /** @param  list<string>  $sections */
    public static function setAttendanceSections(array $sections): void
    {
        $sections = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $sections
        ))));

        static::updateOrCreate(
            ['key' => self::KEY_ATTENDANCE_SECTIONS],
            ['value' => json_encode($sections, JSON_UNESCAPED_UNICODE)]
        );
    }

    public static function scanSmsArrivalTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_ARRIVAL)->value('value')
            ?? static::where('key', self::KEY_SCAN_SMS)->value('value')
            ?? 'Hello {name}, your child checked in at school at {time} ({status}).';
    }

    public static function scanSmsDepartureTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_DEPARTURE)->value('value')
            ?? 'Hello {name}, your child scanned out at school at {time} ({status}). Have a safe trip home.';
    }

    public static function scanSmsMorningInTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_MORNING_IN)->value('value')
            ?? 'Hello {name}, your child checked in this morning at {time}.';
    }

    public static function scanSmsLunchOutTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_LUNCH_OUT)->value('value')
            ?? 'Hello {name}, your child scanned out for lunch/break at {time}.';
    }

    public static function scanSmsAfternoonInTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_AFTERNOON_IN)->value('value')
            ?? 'Hello {name}, your child checked in for the afternoon session at {time}.';
    }

    public static function scanSmsEodOutTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_EOD_OUT)->value('value')
            ?? 'Hello {name}, your child scanned out at the end of the school day at {time}.';
    }

    public static function scanSmsMissedEodTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_MISSED_EOD)->value('value')
            ?? 'Hello {name}, your child did not scan out at the end of the school day. An automatic checkout was recorded at {time}.';
    }

    public static function smsConsecutiveLateTemplate(): string
    {
        return static::where('key', self::KEY_SMS_CONSECUTIVE_LATE)->value('value')
            ?? 'Hello {name}, your child has been late {count} consecutive school days. Please contact the school.';
    }

    public static function smsConsecutiveAbsentTemplate(): string
    {
        return static::where('key', self::KEY_SMS_CONSECUTIVE_ABSENT)->value('value')
            ?? 'Hello {name}, your child has been absent {count} consecutive school days. Please contact the school.';
    }

    public static function smsConsecutiveLateAlertsEnabled(): bool
    {
        return static::booleanSetting(self::KEY_SMS_CONSECUTIVE_LATE_ENABLED, true);
    }

    public static function smsConsecutiveAbsentAlertsEnabled(): bool
    {
        return static::booleanSetting(self::KEY_SMS_CONSECUTIVE_ABSENT_ENABLED, true);
    }

    public static function setSmsConsecutiveLateAlertsEnabled(bool $enabled): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_SMS_CONSECUTIVE_LATE_ENABLED],
            ['value' => $enabled ? '1' : '0']
        );
    }

    public static function setSmsConsecutiveAbsentAlertsEnabled(bool $enabled): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_SMS_CONSECUTIVE_ABSENT_ENABLED],
            ['value' => $enabled ? '1' : '0']
        );
    }

    protected static function booleanSetting(string $key, bool $default): bool
    {
        $value = static::where('key', $key)->value('value');

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param  array<string, string>  $templates */
    public static function setSmsTemplates(array $templates): void
    {
        $map = [
            'arrival' => self::KEY_SCAN_SMS_ARRIVAL,
            'departure' => self::KEY_SCAN_SMS_DEPARTURE,
            'morning_in' => self::KEY_SCAN_SMS_MORNING_IN,
            'lunch_out' => self::KEY_SCAN_SMS_LUNCH_OUT,
            'afternoon_in' => self::KEY_SCAN_SMS_AFTERNOON_IN,
            'eod_out' => self::KEY_SCAN_SMS_EOD_OUT,
            'missed_eod' => self::KEY_SCAN_SMS_MISSED_EOD,
            'consecutive_late' => self::KEY_SMS_CONSECUTIVE_LATE,
            'consecutive_absent' => self::KEY_SMS_CONSECUTIVE_ABSENT,
        ];

        foreach ($map as $field => $key) {
            if (isset($templates[$field]) && trim($templates[$field]) !== '') {
                static::updateOrCreate(['key' => $key], ['value' => trim($templates[$field])]);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function attendancePolicy(): array
    {
        $raw = static::where('key', self::KEY_ATTENDANCE_POLICY)->value('value');

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $policy */
    public static function setAttendancePolicy(array $policy): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_ATTENDANCE_POLICY],
            ['value' => json_encode($policy, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * SMS modem SIM load tracker (loaded_on + validity days).
     *
     * @return array{
     *   set: bool,
     *   status: 'unset'|'ok'|'warning'|'expired',
     *   loaded_on: ?string,
     *   days: ?int,
     *   warn_days: int,
     *   expires_on: ?string,
     *   days_left: ?int,
     *   label: string,
     * }
     */
    public static function smsSimLoadStatus(): array
    {
        $raw = static::where('key', self::KEY_SMS_SIM_LOAD)->value('value');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $warnDays = 3;

        if (! is_array($data) || empty($data['loaded_on']) || empty($data['days'])) {
            return [
                'set' => false,
                'status' => 'unset',
                'loaded_on' => null,
                'days' => null,
                'warn_days' => $warnDays,
                'expires_on' => null,
                'days_left' => null,
                'label' => 'No SIM load recorded yet.',
            ];
        }

        $loadedOn = (string) $data['loaded_on'];
        $days = max(1, (int) $data['days']);
        $warnDays = max(1, (int) ($data['warn_days'] ?? 3));
        $tz = config('app.timezone', 'Asia/Manila');

        try {
            $loaded = \Carbon\Carbon::parse($loadedOn, $tz)->startOfDay();
        } catch (\Throwable) {
            return [
                'set' => false,
                'status' => 'unset',
                'loaded_on' => null,
                'days' => null,
                'warn_days' => $warnDays,
                'expires_on' => null,
                'days_left' => null,
                'label' => 'No SIM load recorded yet.',
            ];
        }

        // Expires at end of (loaded_on + days - 1) calendar days? Usually load for N days
        // means expires after N full days from load day: loaded Aug 5 + 30 days = expires Sep 4.
        $expires = $loaded->copy()->addDays($days);
        $today = now($tz)->startOfDay();
        $daysLeft = (int) $today->diffInDays($expires, false);

        if ($daysLeft < 0) {
            $status = 'expired';
            $label = 'SIM load expired '.abs($daysLeft).' day(s) ago (ended '.$expires->toDateString().'). Reload the SIM.';
        } elseif ($daysLeft <= $warnDays) {
            $status = 'warning';
            $label = $daysLeft === 0
                ? 'SIM load expires today ('.$expires->toDateString().'). Reload soon.'
                : 'SIM load expires in '.$daysLeft.' day(s) ('.$expires->toDateString().').';
        } else {
            $status = 'ok';
            $label = 'SIM load OK — '.$daysLeft.' day(s) left (until '.$expires->toDateString().').';
        }

        return [
            'set' => true,
            'status' => $status,
            'loaded_on' => $loaded->toDateString(),
            'days' => $days,
            'warn_days' => $warnDays,
            'expires_on' => $expires->toDateString(),
            'days_left' => $daysLeft,
            'label' => $label,
        ];
    }

    public static function setSmsSimLoad(string $loadedOn, int $days, int $warnDays = 3): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_SMS_SIM_LOAD],
            ['value' => json_encode([
                'loaded_on' => $loadedOn,
                'days' => max(1, $days),
                'warn_days' => max(1, $warnDays),
                'updated_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE)]
        );
    }
}
