<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'c:\\Users\\THIS PC\\Downloads\\attendance_logs (2).xlsx';
$sheet = IOFactory::load($path)->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);

$header = array_shift($rows);
echo "HEADERS:\n";
print_r($header);
echo "\nTotal data rows: " . count($rows) . "\n";
echo "\nFirst 5 rows:\n";
foreach (array_slice($rows, 0, 5) as $i => $r) {
    echo json_encode($r) . "\n";
}
