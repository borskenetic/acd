<?php
/**
 * Merge extra July 30 findings into july30_autofill_analysis.json
 */
$base = json_decode(file_get_contents(__DIR__ . '/july30_autofill_analysis.json'), true);

$base['export_limitations'] = [
    'course_column' => 'All 2455 rows have Course="Unknown" — cannot grade-filter lunch_out/eod_out expectations from Excel.',
    'section_column' => 'All rows have Section="—" (em dash) — no section/grade signal in export.',
    'source_tags' => 'Excel export has no auto_lunch_out / auto_afternoon_in / auto_eod_out source column; detection is timestamp-pattern only.',
    'timestamp_format' => 'Y-m-d h:i A (minute precision, no seconds). Exact :00 matching remains meaningful for cron-inserted scheduled times.',
];

$base['heuristic_B_exact_clock_clusters']['OUT_12xx_histogram_note'] =
    'Human lunch OUT cluster peaks at 12:11 (30), 12:15 (18), 12:19 (18); exact 12:00 has only 1. Opposite of autofill spike-at-clock.';
$base['heuristic_B_exact_clock_clusters']['IN_13xx_histogram_note'] =
    'Zero scans at exact 13:00; only 10 INs in entire 13:00–13:59 hour, scattered (13:02,13:04,13:09…). Afternoon returns instead cluster ~12:01–12:30.';

$base['heuristic_D_ending_still_IN']['last_IN_by_hour'] = [
    '06' => 76, '07' => 278, '08' => 4, '09' => 7, '10' => 4,
    '11' => 18, '12' => 100, '13' => 5, '14' => 7, '15' => 5,
    '16' => 58, '17' => 36, '18' => 34, '19' => 11, '20' => 7, '21' => 12,
];
$base['heuristic_D_ending_still_IN']['morning_last_IN_before_11'] = 76 + 278 + 4 + 7 + 4; // 369
$base['heuristic_D_ending_still_IN']['note'] =
    '49.6% of students end on IN. 369 end with a morning IN (06–10h) never followed by OUT — strong evidence neither lunch autofill nor EOD auto-out closed them.';

$base['heuristic_E_unfilled_lunch_candidates']['session_eligible_inferred'] = null;
$base['heuristic_E_unfilled_lunch_candidates']['session_eligible_note'] =
    'Cannot infer grade from Course/Section (all Unknown / —). Treat all 365 single morning-IN-only students as potential lunch-autofill candidates if they use the session model.';
$base['heuristic_E_unfilled_lunch_candidates']['by_course'] = ['Unknown' => 365];

$base['verdict'] = [
    'lunch_autofill_ran' => false,
    'lunch_confidence' => 'high',
    'lunch_reasons' => [
        '0 students match classic morning-IN → exact lunch OUT (11:00/11:15/12:00) → exact 13:00 IN signature',
        '0 IN at exact 13:00 vs only 10 human-scattered INs in 13:01–13:59; autofill would spike at 13:00',
        'OUT at exact lunch clocks: 11:00=0, 11:15=0, 12:00=1 vs 285 OUTs in 12:01–12:59 (human midday cluster)',
        '365 students remain with only a morning IN and no lunch OUT / afternoon IN (unfilled candidates)',
        'Only 5 nearby IN→OUT→IN patterns, all with non-clock human times (e.g. OUT 12:39 IN 13:27)',
    ],
    'eod_autofill_ran' => false,
    'eod_confidence' => 'high',
    'eod_reasons' => [
        '662/1335 students (49.6%) still end the day on IN — cron would have inserted OUT for still-IN session students',
        '0 OUTs at or around 22:00 (job asOf time); OUT_22:00_exact=0, window 21:30–22:30=0',
        'Exact grade eod_out clocks are rare and not dominant: 15:00=3, 15:15=0, 16:30=4 vs large human cluster 16:31–17:xx (215 other 16:xx OUTs)',
        'Only 5 students have last OUT at exact eod clock times — consistent with coincidence, not mass autofill',
    ],
    'best_guess' => 'Neither attendance:autofill-lunch nor attendance:auto-eod-out left detectable traces in the 2026-07-30 Excel export. Both appear not to have run (or failed before writing scans).',
];

$base['concrete_counts'] = [
    'total_students' => 1335,
    'total_scan_rows' => 2455,
    'patterns' => $base['pattern_counts'],
    'classic_lunch_exact' => 0,
    'classic_lunch_nearby' => 5,
    'exact_IN_13:00' => 0,
    'exact_OUT_11:00' => 0,
    'exact_OUT_11:15' => 0,
    'exact_OUT_12:00' => 1,
    'OUT_12:01_to_12:59' => 285,
    'IN_13:01_to_13:59' => 10,
    'exact_OUT_15:00' => 3,
    'exact_OUT_15:15' => 0,
    'exact_OUT_16:30' => 4,
    'OUT_around_22:00' => 0,
    'students_ending_on_IN' => 662,
    'students_ending_on_OUT' => 673,
    'single_morning_IN_only_unfilled_candidates' => 365,
];

file_put_contents(
    __DIR__ . '/july30_autofill_analysis.json',
    json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
echo "Updated july30_autofill_analysis.json\n";
