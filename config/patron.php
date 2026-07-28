<?php

return [

    /*
    | Show ID card generate / bulk download on Students & Employees lists.
    */
    'id_cards_enabled' => env('PATRON_ID_CARDS_ENABLED', false),

    /*
    | Year level options keyed by educational_level (see App\Enums\EducationalLevel).
    */
    'senior_high_grades' => ['Grade 11', 'Grade 12'],

    'shs_strands' => [
        'STEM',
        'ABM',
        'HUMSS',
        'GAS',
        'TVL - ICT',
        'TVL - HE',
    ],

    'year_options' => [
        'grade_school' => [
            'Kinder',
            'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6',
        ],
        'high_school_junior' => [
            'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10',
        ],
        'high_school_senior' => [
            'Grade 11', 'Grade 12',
        ],
        'college' => [
            '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', '6th Year',
        ],
    ],

    /*
    | Kinder & grade school may not scan OUT before earliest_out (library policy).
    */
    'early_departure' => [
        'enabled' => env('PATRON_EARLY_DEPARTURE_ENABLED', true),
        // Session-model grades (Kinder–10) use attendance_sessions schedules instead.
        // This cutoff still applies to any remaining non-session patrons if configured.
        'educational_levels' => [],
        'earliest_out' => env('PATRON_EARLIEST_OUT', '16:00'),
        'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),
        'message' => 'You are not yet allowed to scan OUT. Please try again after {time}.',
    ],

];
