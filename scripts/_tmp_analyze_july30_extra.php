<?php
require __DIR__ . '/../vendor/autoload.php';
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'c:\\Users\\THIS PC\\Downloads\\attendance_logs (2).xlsx';
$tz = 'Asia/Manila';
$sheet = IOFactory::load($path)->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);
array_shift($rows);

$courses = [];
$sections = [];
$byStudent = [];

foreach ($rows as $r) {
    $last = trim((string)($r['A'] ?? ''));
    $first = trim((string)($r['B'] ?? ''));
    $course = trim((string)($r['C'] ?? ''));
    $section = trim((string)($r['D'] ?? ''));
    $status = strtoupper(trim((string)($r['E'] ?? '')));
    $raw = trim((string)($r['F'] ?? ''));
    if ($last === '' && $first === '') continue;
    $at = Carbon::parse($raw, $tz);
    $courses[$course] = ($courses[$course] ?? 0) + 1;
    $sections[$section] = ($sections[$section] ?? 0) + 1;
    $key = "$last, $first";
    $byStudent[$key][] = compact('status', 'at', 'raw', 'course', 'section');
}
foreach ($byStudent as &$logs) {
    usort($logs, fn($a,$b) => $a['at']->timestamp <=> $b['at']->timestamp);
}
unset($logs);

arsort($courses);
arsort($sections);
echo "COURSES:\n"; print_r($courses);
echo "SECTIONS (top 40):\n"; print_r(array_slice($sections, 0, 40, true));

// Afternoon OUT distribution after 14:00 for students ending OUT
$lateOut = [];
$pmIn = [];
$endingInByHour = [];
$singleMorningSections = [];
$inOutOnlyLastOutHour = [];

foreach ($byStudent as $name => $logs) {
    $n = count($logs);
    $last = $logs[$n-1];
    if ($last['status'] === 'IN') {
        $h = $last['at']->format('H');
        $endingInByHour[$h] = ($endingInByHour[$h] ?? 0) + 1;
    }
    if ($n === 1 && $logs[0]['status'] === 'IN' && (int)$logs[0]['at']->format('H') < 11) {
        $sec = $logs[0]['section'] ?: '(blank)';
        $singleMorningSections[$sec] = ($singleMorningSections[$sec] ?? 0) + 1;
    }
    // IN->OUT last out time
    if ($n === 2 && $logs[0]['status']==='IN' && $logs[1]['status']==='OUT') {
        $hi = $logs[1]['at']->format('H:i');
        $inOutOnlyLastOutHour[$hi] = ($inOutOnlyLastOutHour[$hi] ?? 0) + 1;
    }
    foreach ($logs as $log) {
        if ($log['status']==='OUT' && (int)$log['at']->format('H') >= 14) {
            $hi = $log['at']->format('H:i');
            $lateOut[$hi] = ($lateOut[$hi] ?? 0) + 1;
        }
        if ($log['status']==='IN' && (int)$log['at']->format('H') >= 12) {
            $hi = $log['at']->format('H:i');
            $pmIn[$hi] = ($pmIn[$hi] ?? 0) + 1;
        }
    }
}
ksort($endingInByHour);
arsort($singleMorningSections);
arsort($inOutOnlyLastOutHour);
arsort($lateOut);
arsort($pmIn);

echo "ENDING IN by hour of last IN:\n"; print_r($endingInByHour);
echo "Single morning IN by section (top 30):\n"; print_r(array_slice($singleMorningSections, 0, 30, true));
echo "IN->OUT only: last OUT times (top 30):\n"; print_r(array_slice($inOutOnlyLastOutHour, 0, 30, true));
echo "OUT after 14:00 (top 30):\n"; print_r(array_slice($lateOut, 0, 30, true));
echo "IN after 12:00 (top 30):\n"; print_r(array_slice($pmIn, 0, 30, true));

// Count how many of the 365 single morning IN are in Grade-like sections
$gradeLike = 0;
$shsLike = 0;
$unknownSec = 0;
foreach ($byStudent as $logs) {
    if (!(count($logs)===1 && $logs[0]['status']==='IN' && (int)$logs[0]['at']->format('H')<11)) continue;
    $s = $logs[0]['section'];
    if (preg_match('/grade\s*(?:[1-9]|10)\b/i', $s) || preg_match('/\bG(?:rade)?\s*[1-9]\b/i', $s) || preg_match('/^(?:Grade\s*)?(?:[1-9]|10)[- ]/i', $s)) {
        $gradeLike++;
    } elseif (preg_match('/grade\s*1[12]|SHS|STEM|ABM|HUMSS|GAS|TVL/i', $s.$logs[0]['course'])) {
        $shsLike++;
    } else {
        $unknownSec++;
    }
}
echo "single morning: gradeLike=$gradeLike shsLike=$shsLike other=$unknownSec\n";

// Exact noon OUT ±0 vs human cluster around lunch for grades 4-10 pattern
$out1200window = [];
for ($m=0; $m<60; $m++) {
    $key = sprintf('12:%02d', $m);
    $out1200window[$key] = 0;
}
foreach ($byStudent as $logs) {
    foreach ($logs as $log) {
        if ($log['status']==='OUT' && (int)$log['at']->format('H')===12) {
            $out1200window[$log['at']->format('H:i')]++;
        }
    }
}
echo "OUT minute histogram 12:xx:\n"; print_r($out1200window);

$in1300window = [];
for ($m=0; $m<60; $m++) {
    $in1300window[sprintf('13:%02d', $m)] = 0;
}
foreach ($byStudent as $logs) {
    foreach ($logs as $log) {
        if ($log['status']==='IN' && (int)$log['at']->format('H')===13) {
            $in1300window[$log['at']->format('H:i')]++;
        }
    }
}
echo "IN minute histogram 13:xx:\n"; print_r($in1300window);
