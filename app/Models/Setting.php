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

    public const KEY_SMS_CONSECUTIVE_LATE = 'sms_consecutive_late';

    public const KEY_SMS_CONSECUTIVE_ABSENT = 'sms_consecutive_absent';

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
            ?? 'Hello {name}, your child checked in at the library at {time} ({status}).';
    }

    public static function scanSmsDepartureTemplate(): string
    {
        return static::where('key', self::KEY_SCAN_SMS_DEPARTURE)->value('value')
            ?? 'Hello {name}, your child scanned at the library at {time} ({status}). Have a safe trip home.';
    }

    public static function smsConsecutiveLateTemplate(): string
    {
        return static::where('key', self::KEY_SMS_CONSECUTIVE_LATE)->value('value')
            ?? 'Hello, {name} has been late {count} consecutive school days. Please contact the school.';
    }

    public static function smsConsecutiveAbsentTemplate(): string
    {
        return static::where('key', self::KEY_SMS_CONSECUTIVE_ABSENT)->value('value')
            ?? 'Hello, {name} has been absent {count} consecutive school days. Please contact the school.';
    }

    /** @param  array<string, string>  $templates */
    public static function setSmsTemplates(array $templates): void
    {
        $map = [
            'arrival' => self::KEY_SCAN_SMS_ARRIVAL,
            'departure' => self::KEY_SCAN_SMS_DEPARTURE,
            'consecutive_late' => self::KEY_SMS_CONSECUTIVE_LATE,
            'consecutive_absent' => self::KEY_SMS_CONSECUTIVE_ABSENT,
        ];

        foreach ($map as $field => $key) {
            if (isset($templates[$field]) && trim($templates[$field]) !== '') {
                static::updateOrCreate(['key' => $key], ['value' => trim($templates[$field])]);
            }
        }
    }
}
