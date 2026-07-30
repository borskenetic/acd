<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\Carbon;

$path = 'c:\\Users\\THIS PC\\Downloads\\attendance_logs (2).xlsx';
$sheet = IOFactory::load($path)->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);
array_shift($rows);

$tz = 'Asia/Manila';
$status = [];
$courses = [];
$sections = [];
$dates = [];
$inByHour = [];
$outByHour = [];
$parsedFail = 0;
$samplesByCourse = [];

foreach ($rows as $r) {
    $st = strtoupper(trim((string)($r['E'] ?? '')));
    $status[$st] = ($status[$st] ?? 0) + 1;
    $course = trim((string)($r['C'] ?? ''));
    $courses[$course] = ($courses[$course] ?? 0) + 1;
    $sec = trim((string)($r['D'] ?? ''));
    $sections[$sec] = ($sections[$sec] ?? 0) + 1;
    $raw = trim((string)($r['F'] ?? ''));
    try {
        $at = Carbon::parse($raw, $tz);
    } catch (Throwable $e) {
        $parsedFail++;
        continue;
    }
    $dates[$at->toDateString()] = ($dates[$at->toDateString()] ?? 0) + 1;
    $h = $at->format('H');
    if ($st === 'IN') $inByHour[$h] = ($inByHour[$h] ?? 0) + 1;
    if ($st === 'OUT') $outByHour[$h] = ($outByHour[$h] ?? 0) + 1;
    if (!isset($samplesByCourse[$course]) && count($samplesByCourse) < 20) {
        $samplesByCourse[$course] = [$r['A'], $r['B'], $r['D'], $st, $raw];
    }
}

ksort($courses);
ksort($dates);
ksort($inByHour);
ksort($outByHour);
arsort($sections);

echo "STATUS:\n"; print_r($status);
echo "DATES:\n"; print_r($dates);
echo "COURSES (top):\n";
arsort($courses); print_r(array_slice($courses, 0, 40, true));
echo "UNIQUE COURSES: " . count($courses) . "\n";
echo "SECTIONS (top 20):\n"; print_r(array_slice($sections, 0, 20, true));
echo "IN by hour:\n"; print_r($inByHour);
echo "OUT by hour:\n"; print_r($outByHour);
echo "parse fails: $parsedFail\n";
echo "sample courses:\n"; print_r($samplesByCourse);
