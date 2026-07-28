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
        'tardy_grace_minutes' => (int) env('ATTENDANCE_TARDY_GRACE_MINUTES', 15),
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
