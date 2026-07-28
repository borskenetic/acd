<?php

return [

    'timezone' => env('ATTENDANCE_SESSIONS_TIMEZONE', 'Asia/Manila'),

    /*
    | Anti-rescan cooldowns (minutes).
    */
    'cooldown_minutes' => (int) env('ATTENDANCE_SCAN_COOLDOWN_MINUTES', 15),
    'lunch_cooldown_minutes' => (int) env('ATTENDANCE_LUNCH_COOLDOWN_MINUTES', 5),

    /*
    | Scheduled autos (Asia/Manila).
    */
    'lunch_autofill_at' => env('ATTENDANCE_LUNCH_AUTOFILL_AT', '13:00'),
    'eod_auto_out_at' => env('ATTENDANCE_EOD_AUTO_OUT_AT', '22:00'),

    /*
    | Grades 1–10: Friday PM is asynchronous → half day (morning IN + morning dismissal OUT).
    */
    'friday_half_day' => (bool) env('ATTENDANCE_FRIDAY_HALF_DAY', true),

    /*
    | Session schedules keyed by year group (Elementary + JHS only).
    | Times are H:i in the sessions timezone.
    */
    'schedules' => [

        'kinder' => [
            'years' => ['Kinder'],
            'half_day' => true,
            'half_day_out' => '10:30',
            'lunch_out' => null,
            'afternoon_in' => null,
            'eod_out' => null,
        ],

        'grades_1_2' => [
            'years' => ['Grade 1', 'Grade 2'],
            'half_day' => false,
            'half_day_out' => null,
            'lunch_out' => '11:00',
            'afternoon_in' => '13:00',
            'eod_out' => '15:00',
        ],

        'grade_3' => [
            'years' => ['Grade 3'],
            'half_day' => false,
            'half_day_out' => null,
            'lunch_out' => '11:15',
            'afternoon_in' => '13:00',
            'eod_out' => '15:15',
        ],

        'grades_4_10' => [
            'years' => [
                'Grade 4', 'Grade 5', 'Grade 6',
                'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10',
            ],
            'half_day' => false,
            'half_day_out' => null,
            'lunch_out' => '12:00',
            'afternoon_in' => '13:00',
            'eod_out' => '16:30',
        ],

    ],

];
