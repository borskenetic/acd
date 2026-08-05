<?php

return [

  /*
  | Grade levels for SF2 (Kinder–12; college excluded).
  */
  'grade_levels' => [
    'Kinder',
    'Grade 1',
    'Grade 2',
    'Grade 3',
    'Grade 4',
    'Grade 5',
    'Grade 6',
    'Grade 7',
    'Grade 8',
    'Grade 9',
    'Grade 10',
    'Grade 11',
    'Grade 12',
  ],

  /*
  | School-day columns on older DepEd grids; SHS template uses full calendar months.
  */
  'max_day_columns' => 31,

  'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),

  /*
  | School-wide gate: first IN after this time (plus grace) counts as tardy.
  */
  'class_start_time' => env('SF2_CLASS_START_TIME', '07:30'),
  'tardy_grace_minutes' => (int) env('SF2_TARDY_GRACE_MINUTES', 5),

  'month_names' => [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December',
  ],

  /*
  | Defaults for SF2-SHS header (Assumption College of Davao).
  */
  'school' => [
    'name' => env('SF2_SCHOOL_NAME', 'ASSUMPTION COLLEGE OF DAVAO'),
    'school_id' => env('SF2_SCHOOL_ID', '405431'),
    'division' => env('SF2_DIVISION', 'DAVAO CITY'),
    'region' => env('SF2_REGION', 'XI'),
    'semester' => env('SF2_SEMESTER', 'FIRST SEMESTER'),
    'track_and_strand' => env('SF2_TRACK_STRAND', 'ARTS, SOCIAL SCIENCES, AND HUMANITIES'),
    'tvl_courses' => env('SF2_TVL_COURSES', ''),
  ],

  /*
  | Official ACD SF2-SHS multi-month workbook (user-provided SF2.xlsx).
  | Sheets: REMINDERS + JUNE…APRIL. Marks: blank=present, X=absent, T=tardy.
  */
  'excel' => [
    'template' => resource_path('templates/sf2/sf2-template.xlsx'),
    'month_sheets' => [
      1 => 'JANUARY',
      2 => 'FEBRUARY',
      3 => 'MARCH',
      4 => 'APRIL',
      5 => 'MAY',
      6 => 'JUNE',
      7 => 'JULY',
      8 => 'AUGUST',
      9 => 'SEPTEMBER',
      10 => 'OCTOBER',
      11 => 'NOVEMBER',
      12 => 'DECEMBER',
    ],
    'first_day_col' => 'C',
    'date_header_row' => 12,
    'dow_header_row' => 13,
    'number_col' => 'A',
    'name_col' => 'B',
    'absent_col' => 'AJ',
    'tardy_col' => 'AK',
    'male_first_row' => 14,
    'male_last_row' => 27,
    'female_first_row' => 29,
    'female_last_row' => 64,
  ],

];
