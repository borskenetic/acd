<?php
/**
 * Analyze attendance_logs Excel for Laravel cron autofill signatures on 2026-07-30.
 */
require __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'c:\\Users\\THIS PC\\Downloads\\attendance_logs (2).xlsx';
$targetDate = '2026-07-30';
$tz = 'Asia/Manila';

$sheet = IOFactory::load($path)->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);
$header = array_shift($rows);

// Detect format from first few scanned_at values
$formatSamples = [];
$hasSeconds = false;
$parseFails = 0;
$totalRows = 0;
$dateCounts = [];

/** @var array<string, list<array{status:string,at:Carbon,raw:string,course:string,section:string}>> $byStudent */
$byStudent = [];

foreach ($rows as $r) {
    $last = trim((string) ($r['A'] ?? ''));
    $first = trim((string) ($r['B'] ?? ''));
    $course = trim((string) ($r['C'] ?? ''));
    $section = trim((string) ($r['D'] ?? ''));
    $status = strtoupper(trim((string) ($r['E'] ?? '')));
    $raw = trim((string) ($r['F'] ?? ''));

    if ($last === '' && $first === '') {
        continue;
    }

    $totalRows++;
    if (count($formatSamples) < 15) {
        $formatSamples[] = $raw;
    }
    if (preg_match('/:\d{2}:\d{2}/', $raw) || preg_match('/\d{1,2}:\d{2}:\d{2}/', $raw)) {
        $hasSeconds = true;
    }

    try {
        $at = Carbon::parse($raw, $tz);
    } catch (Throwable $e) {
        $parseFails++;
        continue;
    }

    $dateCounts[$at->toDateString()] = ($dateCounts[$at->toDateString()] ?? 0) + 1;

    if ($at->toDateString() !== $targetDate) {
        continue;
    }

    $key = $last . ', ' . $first;
    $byStudent[$key][] = [
        'status' => $status,
        'at' => $at,
        'raw' => $raw,
        'course' => $course,
        'section' => $section,
    ];
}

foreach ($byStudent as $key => &$logs) {
    usort($logs, fn ($a, $b) => $a['at']->timestamp <=> $b['at']->timestamp);
}
unset($logs);

$totalStudents = count($byStudent);

// Grade mapping from Course string (best-effort)
function inferLunchOut(string $course): ?string
{
    $c = strtolower($course);
    if (str_contains($c, 'kinder') || str_contains($c, 'kindergarten')) {
        return null; // half-day, no lunch autofill
    }
    if (preg_match('/\bgrade\s*1\b|\bg1\b|\b1\b/', $c) && (str_contains($c, 'grade') || preg_match('/^1\b/', $c))) {
        // handled below more carefully
    }
    if (preg_match('/grade\s*3\b/i', $course)) {
        return '11:15';
    }
    if (preg_match('/grade\s*[12]\b/i', $course) || preg_match('/\bG-?[12]\b/i', $course)) {
        return '11:00';
    }
    if (preg_match('/grade\s*(?:[4-9]|10)\b/i', $course) || preg_match('/\bG-?(?:[4-9]|10)\b/i', $course)) {
        return '12:00';
    }
    // SHS / other — not session model
    return null;
}

function inferEodOut(string $course): ?string
{
    if (preg_match('/grade\s*3\b/i', $course)) {
        return '15:15';
    }
    if (preg_match('/grade\s*[12]\b/i', $course)) {
        return '15:00';
    }
    if (preg_match('/grade\s*(?:[4-9]|10)\b/i', $course)) {
        return '16:30';
    }
    return null;
}

function isExactClock(Carbon $at, string $hm): bool
{
    return $at->format('H:i') === $hm;
}

function minuteOfDay(Carbon $at): int
{
    return ((int) $at->format('H')) * 60 + (int) $at->format('i');
}

// Exact clock clusters
$exactOutCounts = [
    '11:00' => 0,
    '11:15' => 0,
    '12:00' => 0,
    '15:00' => 0,
    '15:15' => 0,
    '16:30' => 0,
];
$exactInCounts = [
    '13:00' => 0,
];
$nearbyOut = [
    '11:00' => ['exact' => 0, 'nearby_1_59' => 0], // 11:01-11:14 and wait — define per window
];

// Better nearby windows:
// For lunch OUT targets: count exact vs same hour other minutes
$outAt1100_exact = 0;
$outAt1100_nearby = 0; // 11:01-11:59 excluding 11:15 exact which is another target
$outAt1115_exact = 0;
$outAt1115_nearby = 0; // 11:16-11:59? Better: 11:14 and 11:16 (±1) and hour bucket
$outAt1200_exact = 0;
$outAt1200_sameHour = 0; // 12:01-12:59
$inAt1300_exact = 0;
$inAt1300_sameHour = 0; // 13:01-13:59
$outAt1500_exact = 0;
$outAt1500_sameHour = 0;
$outAt1515_exact = 0;
$outAt1630_exact = 0;
$outAt1630_sameHour = 0; // 16:31-16:59
$outAround2200 = 0; // 21:30-22:30
$outExact2200 = 0;

$outByHi = []; // H:i => count for OUT
$inByHi = [];

foreach ($byStudent as $logs) {
    foreach ($logs as $log) {
        $hi = $log['at']->format('H:i');
        if ($log['status'] === 'OUT') {
            $outByHi[$hi] = ($outByHi[$hi] ?? 0) + 1;
            if (isset($exactOutCounts[$hi])) {
                $exactOutCounts[$hi]++;
            }
            if ($hi === '11:00') {
                $outAt1100_exact++;
            } elseif ((int) $log['at']->format('H') === 11 && $hi !== '11:15') {
                $outAt1100_nearby++;
            }
            if ($hi === '11:15') {
                $outAt1115_exact++;
            }
            if ($hi === '12:00') {
                $outAt1200_exact++;
            } elseif ((int) $log['at']->format('H') === 12) {
                $outAt1200_sameHour++;
            }
            if ($hi === '15:00') {
                $outAt1500_exact++;
            } elseif ((int) $log['at']->format('H') === 15 && $hi !== '15:15') {
                $outAt1500_sameHour++;
            }
            if ($hi === '15:15') {
                $outAt1515_exact++;
            }
            if ($hi === '16:30') {
                $outAt1630_exact++;
            } elseif ((int) $log['at']->format('H') === 16) {
                $outAt1630_sameHour++;
            }
            $mins = minuteOfDay($log['at']);
            if ($mins >= 21 * 60 + 30 && $mins <= 22 * 60 + 30) {
                $outAround2200++;
            }
            if ($hi === '22:00') {
                $outExact2200++;
            }
        }
        if ($log['status'] === 'IN') {
            $inByHi[$hi] = ($inByHi[$hi] ?? 0) + 1;
            if ($hi === '13:00') {
                $inAt1300_exact++;
                $exactInCounts['13:00']++;
            } elseif ((int) $log['at']->format('H') === 13) {
                $inAt1300_sameHour++;
            }
        }
    }
}

arsort($outByHi);
arsort($inByHi);
$topOutTimes = array_slice($outByHi, 0, 25, true);
$topInTimes = array_slice($inByHi, 0, 25, true);

// Per-student pattern analysis
$classicLunchExact = 0; // IN(morning) → OUT(exact lunch) → IN(exact 13:00)
$classicLunchNearby = 0; // same pattern but OUT/IN near clock but not exact
$classicLunchAnyClock = 0;
$classicLunchExamples = [];
$classicLunchNearbyExamples = [];

$endingOnIn = 0;
$endingOnOut = 0;
$endingOnInExamples = [];

$singleMorningInOnly = 0; // autofill candidates left unfilled
$singleMorningInExamples = [];

$singleInAny = 0;
$patternCounts = [
    'IN_only' => 0,
    'OUT_only' => 0,
    'IN_OUT' => 0,
    'IN_OUT_IN' => 0,
    'IN_OUT_IN_OUT' => 0,
    'other' => 0,
];

$eodExactSignature = 0; // last scan is OUT at exact eod times
$eodAsOfSignature = 0; // last OUT around 22:00
$eodExactExamples = [];

$studentsWith13ExactIn = 0;
$studentsWithExactLunchOut = 0;

foreach ($byStudent as $name => $logs) {
    $statuses = array_map(fn ($l) => $l['status'], $logs);
    $seq = implode('→', $statuses);
    $n = count($logs);
    $course = $logs[0]['course'] ?? '';

    $last = $logs[$n - 1];
    if ($last['status'] === 'IN') {
        $endingOnIn++;
        if (count($endingOnInExamples) < 15) {
            $endingOnInExamples[] = [
                'name' => $name,
                'course' => $course,
                'last_at' => $last['raw'],
                'seq' => $seq,
                'scan_count' => $n,
            ];
        }
    } else {
        $endingOnOut++;
    }

    // Pattern bucket
    if ($seq === 'IN') {
        $patternCounts['IN_only']++;
    } elseif ($seq === 'OUT') {
        $patternCounts['OUT_only']++;
    } elseif ($seq === 'IN→OUT') {
        $patternCounts['IN_OUT']++;
    } elseif ($seq === 'IN→OUT→IN') {
        $patternCounts['IN_OUT_IN']++;
    } elseif ($seq === 'IN→OUT→IN→OUT') {
        $patternCounts['IN_OUT_IN_OUT']++;
    } else {
        $patternCounts['other']++;
    }

    // Single morning IN only (autofill candidate left)
    if ($n === 1 && $logs[0]['status'] === 'IN') {
        $singleInAny++;
        $h = (int) $logs[0]['at']->format('H');
        if ($h < 11) {
            $singleMorningInOnly++;
            if (count($singleMorningInExamples) < 20) {
                $singleMorningInExamples[] = [
                    'name' => $name,
                    'course' => $course,
                    'section' => $logs[0]['section'],
                    'at' => $logs[0]['raw'],
                    'inferred_lunch_out' => inferLunchOut($course),
                ];
            }
        }
    }

    // Classic lunch autofill: look for IN → OUT → IN (possibly followed by more)
    // Find first three that match morning IN, lunch OUT, afternoon IN
    if ($n >= 3 && $logs[0]['status'] === 'IN' && $logs[1]['status'] === 'OUT' && $logs[2]['status'] === 'IN') {
        $in1 = $logs[0]['at'];
        $out = $logs[1]['at'];
        $in2 = $logs[2]['at'];
        $morningOk = (int) $in1->format('H') < 11;
        $outHi = $out->format('H:i');
        $in2Hi = $in2->format('H:i');
        $outIsClock = in_array($outHi, ['11:00', '11:15', '12:00'], true);
        $in2IsExact1300 = ($in2Hi === '13:00');

        if ($morningOk && $outIsClock && $in2IsExact1300) {
            $classicLunchExact++;
            if (count($classicLunchExamples) < 20) {
                $classicLunchExamples[] = [
                    'name' => $name,
                    'course' => $course,
                    'in1' => $logs[0]['raw'],
                    'out' => $logs[1]['raw'],
                    'in2' => $logs[2]['raw'],
                    'seq' => $seq,
                ];
            }
        } elseif ($morningOk && (
            // nearby: OUT at 11:00±0 target hour or 12:00 hour, IN2 in 13:xx
            (in_array((int) $out->format('H'), [11, 12], true) && (int) $in2->format('H') === 13)
            && !($outIsClock && $in2IsExact1300)
        )) {
            // Nearby human-ish: OUT in lunch window but not exact clock pair, OR exact out but not exact 13:00
            $classicLunchNearby++;
            if (count($classicLunchNearbyExamples) < 15) {
                $classicLunchNearbyExamples[] = [
                    'name' => $name,
                    'course' => $course,
                    'in1' => $logs[0]['raw'],
                    'out' => $logs[1]['raw'],
                    'in2' => $logs[2]['raw'],
                ];
            }
        }

        if ($morningOk && $outIsClock && $in2IsExact1300) {
            $classicLunchAnyClock++;
        }
    }

    // Student-level exact markers
    $hasExact1300In = false;
    $hasExactLunchOut = false;
    foreach ($logs as $log) {
        if ($log['status'] === 'IN' && $log['at']->format('H:i') === '13:00') {
            $hasExact1300In = true;
        }
        if ($log['status'] === 'OUT' && in_array($log['at']->format('H:i'), ['11:00', '11:15', '12:00'], true)) {
            $hasExactLunchOut = true;
        }
    }
    if ($hasExact1300In) {
        $studentsWith13ExactIn++;
    }
    if ($hasExactLunchOut) {
        $studentsWithExactLunchOut++;
    }

    // EOD: last status OUT at exact eod or ~22:00
    if ($last['status'] === 'OUT') {
        $hi = $last['at']->format('H:i');
        if (in_array($hi, ['15:00', '15:15', '16:30'], true)) {
            $eodExactSignature++;
            if (count($eodExactExamples) < 15) {
                $eodExactExamples[] = [
                    'name' => $name,
                    'course' => $course,
                    'out' => $last['raw'],
                    'seq' => $seq,
                ];
            }
        }
        $mins = minuteOfDay($last['at']);
        if ($mins >= 21 * 60 + 30 && $mins <= 22 * 60 + 30) {
            $eodAsOfSignature++;
        }
    }
}

// Course distribution for classic lunch exact
$classicByCourse = [];
foreach ($byStudent as $name => $logs) {
    $n = count($logs);
    if ($n >= 3 && $logs[0]['status'] === 'IN' && $logs[1]['status'] === 'OUT' && $logs[2]['status'] === 'IN') {
        $in1 = $logs[0]['at'];
        $out = $logs[1]['at'];
        $in2 = $logs[2]['at'];
        if ((int) $in1->format('H') < 11
            && in_array($out->format('H:i'), ['11:00', '11:15', '12:00'], true)
            && $in2->format('H:i') === '13:00') {
            $c = $logs[0]['course'] ?: '(blank)';
            $classicByCourse[$c] = ($classicByCourse[$c] ?? 0) + 1;
        }
    }
}
arsort($classicByCourse);

// Single morning IN by course
$singleMorningByCourse = [];
foreach ($singleMorningInExamples as $ex) {
    // rebuild properly for all
}
$singleMorningByCourse = [];
$sessionEligibleSingleMorning = 0;
foreach ($byStudent as $name => $logs) {
    if (count($logs) === 1 && $logs[0]['status'] === 'IN' && (int) $logs[0]['at']->format('H') < 11) {
        $c = $logs[0]['course'] ?: '(blank)';
        $singleMorningByCourse[$c] = ($singleMorningByCourse[$c] ?? 0) + 1;
        if (inferLunchOut($logs[0]['course']) !== null) {
            $sessionEligibleSingleMorning++;
        }
    }
}
arsort($singleMorningByCourse);

// Also count IN→OUT→IN→OUT with exact lunch+13:00 (full day autofill + human/eod out)
$classicThenOut = 0;
$classicThenOutExactEod = 0;
foreach ($byStudent as $name => $logs) {
    if (count($logs) >= 4
        && $logs[0]['status'] === 'IN'
        && $logs[1]['status'] === 'OUT'
        && $logs[2]['status'] === 'IN'
        && $logs[3]['status'] === 'OUT'
    ) {
        if ((int) $logs[0]['at']->format('H') < 11
            && in_array($logs[1]['at']->format('H:i'), ['11:00', '11:15', '12:00'], true)
            && $logs[2]['at']->format('H:i') === '13:00') {
            $classicThenOut++;
            if (in_array($logs[3]['at']->format('H:i'), ['15:00', '15:15', '16:30', '22:00'], true)) {
                $classicThenOutExactEod++;
            }
        }
    }
}

// Confidence heuristics
$lunchLikely = false;
$lunchConfidence = 'low';
$lunchReason = [];

if ($classicLunchExact >= 20 && $inAt1300_exact > $inAt1300_sameHour * 2) {
    $lunchLikely = true;
    $lunchConfidence = 'high';
    $lunchReason[] = "{$classicLunchExact} students match classic IN→OUT(exact lunch)→IN(13:00) signature";
    $lunchReason[] = "exact 13:00 IN ({$inAt1300_exact}) dominates same-hour non-exact ({$inAt1300_sameHour})";
} elseif ($classicLunchExact >= 5 || ($inAt1300_exact >= 10 && $inAt1300_exact > $inAt1300_sameHour)) {
    $lunchLikely = true;
    $lunchConfidence = 'medium';
    $lunchReason[] = "{$classicLunchExact} classic exact signatures; {$inAt1300_exact} exact 13:00 INs vs {$inAt1300_sameHour} other 13:xx";
} elseif ($classicLunchExact === 0 && $singleMorningInOnly > 50) {
    $lunchLikely = false;
    $lunchConfidence = 'high';
    $lunchReason[] = "0 classic lunch signatures and {$singleMorningInOnly} students left with only morning IN (unfilled candidates)";
} elseif ($classicLunchExact === 0) {
    $lunchLikely = false;
    $lunchConfidence = 'medium';
    $lunchReason[] = 'No classic exact lunch autofill patterns found';
} else {
    $lunchLikely = $classicLunchExact > 0;
    $lunchConfidence = 'low';
    $lunchReason[] = "Ambiguous: {$classicLunchExact} exact vs {$classicLunchNearby} nearby patterns; {$singleMorningInOnly} unfilled morning-IN-only";
}

$eodLikely = false;
$eodConfidence = 'low';
$eodReason = [];

if ($endingOnIn > $totalStudents * 0.3 && $eodExactSignature < 10 && $eodAsOfSignature < 5) {
    $eodLikely = false;
    $eodConfidence = 'high';
    $eodReason[] = "{$endingOnIn}/{$totalStudents} students end on IN with few exact EOD OUTs ({$eodExactSignature}) and ~22:00 OUTs ({$eodAsOfSignature})";
} elseif ($eodExactSignature >= 20 || $eodAsOfSignature >= 20) {
    $eodLikely = true;
    $eodConfidence = $endingOnIn < $totalStudents * 0.1 ? 'high' : 'medium';
    $eodReason[] = "{$eodExactSignature} last-OUT at exact eod times; {$eodAsOfSignature} last-OUT ~22:00; {$endingOnIn} still ending IN";
} elseif ($endingOnIn >= 10) {
    $eodLikely = false;
    $eodConfidence = 'medium';
    $eodReason[] = "{$endingOnIn} students still ending on IN suggests EOD cron did not close them";
} else {
    $eodLikely = $eodExactSignature > 0;
    $eodConfidence = 'low';
    $eodReason[] = "Limited EOD signal: exact={$eodExactSignature}, ~22:00={$eodAsOfSignature}, ending IN={$endingOnIn}";
}

$summary = [
    'analysis_date' => $targetDate,
    'timezone' => $tz,
    'source_file' => $path,
    'scanned_at_format' => [
        'samples' => $formatSamples,
        'has_seconds' => $hasSeconds,
        'note' => $hasSeconds
            ? 'Timestamps appear to include seconds; exact :00 matching is precise.'
            : 'Timestamps appear minute-precision (Y-m-d h:i A style without seconds); exact :00 matching is still useful but cannot distinguish :00:00 from missing seconds.',
    ],
    'totals' => [
        'excel_rows_parsed' => $totalRows,
        'parse_fails' => $parseFails,
        'rows_by_date' => $dateCounts,
        'students_on_target_date' => $totalStudents,
        'scan_rows_on_target_date' => array_sum(array_map('count', $byStudent)),
    ],
    'pattern_counts' => $patternCounts,
    'heuristic_A_classic_lunch_autofill' => [
        'exact_clock_signature_students' => $classicLunchExact,
        'nearby_humanish_IN_OUT_IN_students' => $classicLunchNearby,
        'exact_then_afternoon_OUT_students' => $classicThenOut,
        'exact_then_exact_EOD_OUT_students' => $classicThenOutExactEod,
        'by_course' => $classicByCourse,
        'examples_exact' => $classicLunchExamples,
        'examples_nearby' => $classicLunchNearbyExamples,
    ],
    'heuristic_B_exact_clock_clusters' => [
        'OUT_exact' => $exactOutCounts,
        'IN_exact' => $exactInCounts,
        'comparisons' => [
            'OUT_11:00_exact' => $outAt1100_exact,
            'OUT_11xx_other_excl_11:15' => $outAt1100_nearby,
            'OUT_11:15_exact' => $outAt1115_exact,
            'OUT_12:00_exact' => $outAt1200_exact,
            'OUT_12:01-12:59' => $outAt1200_sameHour,
            'IN_13:00_exact' => $inAt1300_exact,
            'IN_13:01-13:59' => $inAt1300_sameHour,
            'OUT_15:00_exact' => $outAt1500_exact,
            'OUT_15xx_other_excl_15:15' => $outAt1500_sameHour,
            'OUT_15:15_exact' => $outAt1515_exact,
            'OUT_16:30_exact' => $outAt1630_exact,
            'OUT_16xx_other' => $outAt1630_sameHour,
            'OUT_around_22:00_21:30-22:30' => $outAround2200,
            'OUT_22:00_exact' => $outExact2200,
        ],
        'students_with_exact_13:00_IN' => $studentsWith13ExactIn,
        'students_with_exact_lunch_OUT' => $studentsWithExactLunchOut,
        'top_OUT_times' => $topOutTimes,
        'top_IN_times' => $topInTimes,
    ],
    'heuristic_C_EOD_autofill' => [
        'students_last_OUT_exact_eod_15:00_15:15_16:30' => $eodExactSignature,
        'students_last_OUT_around_22:00' => $eodAsOfSignature,
        'examples_exact_eod' => $eodExactExamples,
    ],
    'heuristic_D_ending_still_IN' => [
        'count' => $endingOnIn,
        'ending_OUT' => $endingOnOut,
        'pct_of_students' => $totalStudents > 0 ? round(100 * $endingOnIn / $totalStudents, 1) : 0,
        'examples' => $endingOnInExamples,
    ],
    'heuristic_E_unfilled_lunch_candidates' => [
        'single_morning_IN_only' => $singleMorningInOnly,
        'session_eligible_inferred' => $sessionEligibleSingleMorning,
        'by_course' => array_slice($singleMorningByCourse, 0, 30, true),
        'examples' => $singleMorningInExamples,
    ],
    'verdict' => [
        'lunch_autofill_ran' => $lunchLikely,
        'lunch_confidence' => $lunchConfidence,
        'lunch_reasons' => $lunchReason,
        'eod_autofill_ran' => $eodLikely,
        'eod_confidence' => $eodConfidence,
        'eod_reasons' => $eodReason,
    ],
    'header_detected' => $header,
];

$outDir = __DIR__;
$outFile = $outDir . '/july30_autofill_analysis.json';
file_put_contents($outFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Wrote {$outFile}\n";
echo json_encode([
    'students' => $totalStudents,
    'classic_lunch_exact' => $classicLunchExact,
    'classic_lunch_nearby' => $classicLunchNearby,
    'IN_13:00_exact' => $inAt1300_exact,
    'IN_13:01-59' => $inAt1300_sameHour,
    'OUT_11:00' => $outAt1100_exact,
    'OUT_11:15' => $outAt1115_exact,
    'OUT_12:00' => $outAt1200_exact,
    'OUT_12:01-59' => $outAt1200_sameHour,
    'OUT_15:00' => $outAt1500_exact,
    'OUT_15:15' => $outAt1515_exact,
    'OUT_16:30' => $outAt1630_exact,
    'OUT_~22:00' => $outAround2200,
    'ending_IN' => $endingOnIn,
    'single_morning_IN_only' => $singleMorningInOnly,
    'session_eligible_unfilled' => $sessionEligibleSingleMorning,
    'patterns' => $patternCounts,
    'lunch_ran' => $lunchLikely,
    'lunch_conf' => $lunchConfidence,
    'eod_ran' => $eodLikely,
    'eod_conf' => $eodConfidence,
    'has_seconds' => $hasSeconds,
], JSON_PRETTY_PRINT);
