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
  | DepEd SF2 daily grid uses up to 25 school-day columns.
  */
  'max_day_columns' => 25,

  'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),

  /*
  | School-wide gate: first IN after this time (plus grace) counts as tardy.
  */
  'class_start_time' => env('SF2_CLASS_START_TIME', '07:30'),
  'tardy_grace_minutes' => (int) env('SF2_TARDY_GRACE_MINUTES', 15),

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
  | Official DepEd Excel template (resources/templates/sf2/sf2-template.xlsx).
  | Cell map derived from the division SF2 workbook layout.
  */
  'excel' => [
    'template' => resource_path('templates/sf2/sf2-template.xlsx'),
    'header' => [
      'school_id' => 'C6',
      'school_year' => 'K6',
      'report_month' => 'X6',
      'school_name' => 'C8',
      'grade_level' => 'X8',
      'section' => 'AC8',
    ],
    'date_header_row' => 11,
    'dow_header_row' => 12,
    'first_day_col' => 'D',
    'day_column_count' => 25,
    'absent_col' => 'AC',
    'tardy_col' => 'AD',
    'remarks_col' => 'AE',
    'male_first_row' => 14,
    'male_last_row' => 34,
    'male_total_row' => 35,
    'female_first_row' => 36,
    'female_last_row' => 60,
    'female_total_row' => 61,
    'combined_total_row' => 62,
    'number_col' => 'A',
    'name_col' => 'B',
    'summary' => [
      'month_days_label' => 'AE64',
      'registered_end_month' => 70,
      'school_days_row' => 71,
      'summary_value_cols' => ['AH', 'AI', 'AJ'],
    ],
    'signatures' => [
      'teacher_name' => 'AE88',
      'school_head_name' => 'AE92',
    ],
  ],

];
