<?php

return [

    /*
    | Library-style section picker on the attendance scanner + admin settings UI.
    */
    'section_picker_enabled' => env('ATTENDANCE_SECTION_PICKER_ENABLED', false),

    /*
    | Logout feedback modal on OUT scans + admin settings UI.
    */
    'logout_feedback_enabled' => env('ATTENDANCE_LOGOUT_FEEDBACK_ENABLED', false),

    /*
    | Gate login / logout times and tardy rules (overridable in Admin → Gate policy).
    */
    'gate' => [
        'login_time' => env('ATTENDANCE_GATE_LOGIN_TIME', '07:30'),
        'logout_time' => env('ATTENDANCE_GATE_LOGOUT_TIME', '16:00'),
        'tardy_grace_minutes' => (int) env('ATTENDANCE_TARDY_GRACE_MINUTES', 5),

        /*
        | Senior High day class times (Grade 11–12, non-evening sections).
        | Editable in Admin → Attendance policy; values here are defaults.
        */
        'shs_login_time' => env('ATTENDANCE_SHS_LOGIN_TIME', '12:30'),
        'shs_logout_time' => env('ATTENDANCE_SHS_LOGOUT_TIME', '18:00'),
        'night_login_time' => env('ATTENDANCE_NIGHT_LOGIN_TIME', '16:30'),
        'night_logout_time' => env('ATTENDANCE_NIGHT_LOGOUT_TIME', '21:00'),

        /*
        | Per-year login overrides (H:i) for non-SHS grades if needed.
        | SHS years use shs_login_time above (overridable in policy UI).
        | LATE = first IN after (login + tardy_grace_minutes) for that year.
        | Section schedules below take priority over this map.
        */
        'login_time_by_year' => [
            // Legacy fallback; SHS is driven by shs_login_time in AttendancePolicyService.
        ],

        'evening_sections' => [
            'Abigail',
            'Abigail Evening',
            'Dignity',
            'Dignity Evening',
        ],

        /*
        | Evening / night-shift sections (year + section name).
        | Login/logout times are overridden by policy SHS evening times when set.
        */
        'schedules_by_year_section' => [
            [
                'years' => ['Grade 11', 'Grade 12'],
                'sections' => [
                    'Abigail',
                    'Abigail Evening',
                    'Dignity',
                    'Dignity Evening',
                ],
                'login_time' => env('ATTENDANCE_NIGHT_LOGIN_TIME', '16:30'),
                'logout_time' => env('ATTENDANCE_NIGHT_LOGOUT_TIME', '21:00'),
            ],
        ],
    ],

    /*
    | Parent/guardian SMS on gate scans (emergency_number on student record).
    */
    'sms' => [
        'departure_after' => env('ATTENDANCE_SMS_DEPARTURE_AFTER', '16:00'),
        'consecutive_late_threshold' => (int) env('ATTENDANCE_SMS_LATE_THRESHOLD', 5),
        'consecutive_absent_threshold' => (int) env('ATTENDANCE_SMS_ABSENT_THRESHOLD', 3),
    ],

];
