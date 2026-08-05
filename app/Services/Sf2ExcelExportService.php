<?php

namespace App\Services;

use App\Models\Sf2Report;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fills Assumption’s official SF2-SHS multi-month Excel (resources/templates/sf2/sf2-template.xlsx).
 * Layout: REMINDERS + one sheet per month; calendar Mon–Sat columns; X = absent, T = tardy.
 */
class Sf2ExcelExportService
{
    public function __construct(
        protected Sf2GridBuilder $grid,
    ) {}

    public function download(Sf2Report $report): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($report);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $this->excelFilename($report), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function buildSpreadsheet(Sf2Report $report): Spreadsheet
    {
        $report->loadMissing('students');
        $grid = $this->grid->build($report);

        $templatePath = config('sf2.excel.template');
        if (! is_file($templatePath)) {
            throw new RuntimeException(
                'SF2 Excel template missing. Place the official SF2.xlsx at resources/templates/sf2/sf2-template.xlsx'
            );
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheetName = $this->sheetNameForMonth((int) $report->report_month);

        $sheetTitles = array_map('strtoupper', $spreadsheet->getSheetNames());
        if (! in_array(strtoupper($sheetName), $sheetTitles, true)) {
            throw new RuntimeException(
                "SF2 template has no sheet for {$sheetName}. Available: ".implode(', ', $spreadsheet->getSheetNames())
            );
        }

        // Resolve actual sheet title casing from workbook.
        foreach ($spreadsheet->getSheetNames() as $title) {
            if (strcasecmp($title, $sheetName) === 0) {
                $sheetName = $title;
                break;
            }
        }

        $this->pruneSheets($spreadsheet, $sheetName);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        $dayMap = $this->rebuildCalendarHeaders($sheet, (int) $report->report_year, (int) $report->report_month);
        $meta = $this->detectLayout($sheet);

        $this->fillHeader($sheet, $report, $meta);
        $this->clearLearnerArea($sheet, $meta, $dayMap);
        $this->fillLearnerBlock($sheet, $grid['male'], $meta['male_first_row'], $meta['male_last_row'], $dayMap, $meta);
        $this->fillLearnerBlock($sheet, $grid['female'], $meta['female_first_row'], $meta['female_last_row'], $dayMap, $meta);
        $this->fillSummary($sheet, $report, $grid, $meta);
        $this->fillSignatures($sheet, $report, $meta);

        return $spreadsheet;
    }

    protected function sheetNameForMonth(int $month): string
    {
        $map = config('sf2.excel.month_sheets', []);

        return $map[$month] ?? strtoupper(Carbon::create(null, $month, 1)->format('F'));
    }

    /** Keep REMINDERS + the report month (official multi-tab workbook). */
    protected function pruneSheets(Spreadsheet $spreadsheet, string $keepMonth): void
    {
        $keep = array_map('strtoupper', ['REMINDERS', $keepMonth]);
        for ($i = $spreadsheet->getSheetCount() - 1; $i >= 0; $i--) {
            $name = $spreadsheet->getSheet($i)->getTitle();
            if (! in_array(strtoupper($name), $keep, true)) {
                $spreadsheet->removeSheetByIndex($i);
            }
        }
        foreach ($spreadsheet->getAllSheets() as $idx => $sheet) {
            if (strcasecmp($sheet->getTitle(), $keepMonth) === 0) {
                $spreadsheet->setActiveSheetIndex($idx);
                break;
            }
        }
    }

    /**
     * Lay out day-of-month columns Mon–Sat with blank Sunday separator columns (DepEd SF2-SHS style).
     *
     * @return array<int, string> day-of-month => column letter
     */
    protected function rebuildCalendarHeaders(Worksheet $sheet, int $year, int $month): array
    {
        $firstCol = Coordinate::columnIndexFromString((string) config('sf2.excel.first_day_col', 'C'));
        $absentCol = $this->findColumnByRowLabel($sheet, 13, 'ABSENT')
            ?? $this->findColumnByRowLabel($sheet, 12, 'ABSENT')
            ?? Coordinate::columnIndexFromString('AJ');
        $dateRow = (int) config('sf2.excel.date_header_row', 12);
        $dowRow = (int) config('sf2.excel.dow_header_row', 13);

        // Clear existing day/dow heads (do not touch ABSENT/TARDY).
        for ($c = $firstCol; $c < $absentCol; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $sheet->setCellValue($letter.$dateRow, null);
            $sheet->setCellValue($letter.$dowRow, null);
        }

        $tz = config('sf2.timezone', 'Asia/Manila');
        $daysInMonth = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->daysInMonth;
        $col = $firstCol;
        $dayMap = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            if ($col >= $absentCol) {
                break;
            }

            $date = Carbon::create($year, $month, $day, 0, 0, 0, $tz);
            $letter = Coordinate::stringFromColumnIndex($col);

            if ($date->isSunday()) {
                // Blank separator column (Sunday is not marked on SF2-SHS).
                $col++;
                continue;
            }

            $sheet->setCellValue($letter.$dateRow, $day);
            $sheet->setCellValue($letter.$dowRow, strtoupper($date->format('D')));
            $dayMap[$day] = $letter;
            $col++;
        }

        return $dayMap;
    }

    /**
     * @return array{
     *   male_first_row: int,
     *   male_last_row: int,
     *   female_first_row: int,
     *   female_last_row: int,
     *   number_col: string,
     *   name_col: string,
     *   absent_col: string,
     *   tardy_col: string,
     *   remarks_col: string,
     * }
     */
    protected function detectLayout(Worksheet $sheet): array
    {
        $cfg = config('sf2.excel');
        $absentIdx = $this->findColumnByRowLabel($sheet, 13, 'ABSENT')
            ?? Coordinate::columnIndexFromString($cfg['absent_col'] ?? 'AJ');
        $tardyIdx = $this->findColumnByRowLabel($sheet, 13, 'TARDY')
            ?? Coordinate::columnIndexFromString($cfg['tardy_col'] ?? 'AK');

        return [
            'male_first_row' => (int) ($cfg['male_first_row'] ?? 14),
            'male_last_row' => (int) ($cfg['male_last_row'] ?? 27),
            'female_first_row' => (int) ($cfg['female_first_row'] ?? 29),
            'female_last_row' => (int) ($cfg['female_last_row'] ?? 64),
            'number_col' => (string) ($cfg['number_col'] ?? 'A'),
            'name_col' => (string) ($cfg['name_col'] ?? 'B'),
            'absent_col' => Coordinate::stringFromColumnIndex($absentIdx),
            'tardy_col' => Coordinate::stringFromColumnIndex($tardyIdx),
            'remarks_col' => Coordinate::stringFromColumnIndex($tardyIdx + 1),
        ];
    }

    protected function fillHeader(Worksheet $sheet, Sf2Report $report, array $meta): void
    {
        $defaults = config('sf2.school', []);

        $this->setValueBesideLabel($sheet, 'School Name', $report->school_name);
        $this->setValueBesideLabel($sheet, 'School ID', $report->school_id ?? ($defaults['school_id'] ?? ''));
        $this->setValueBesideLabel($sheet, 'Division', $report->division ?? ($defaults['division'] ?? 'DAVAO CITY'), fuzzy: true);
        $this->setValueBesideLabel($sheet, 'Region', $report->region ?? ($defaults['region'] ?? 'XI'));
        $this->setValueBesideLabel($sheet, 'Semester', $report->semester ?? ($defaults['semester'] ?? 'FIRST SEMESTER'));
        $this->setValueBesideLabel($sheet, 'School Year', $report->school_year);
        $this->setValueBesideLabel($sheet, 'Grade Level', $this->numericGrade($report->grade_level));
        $this->setValueBesideLabel($sheet, 'Track and Strand', $report->track_and_strand ?? ($defaults['track_and_strand'] ?? ''), fuzzy: true);
        // Some months put Track/Strand value only (label merged differently).
        if (filled($report->track_and_strand ?? ($defaults['track_and_strand'] ?? null))) {
            $this->setValueBesideLabel($sheet, 'Track and Strand', $report->track_and_strand ?? $defaults['track_and_strand'], fuzzy: true);
        }
        $this->setValueBesideLabel($sheet, 'Section', $report->section);
        if (filled($report->tvl_courses ?? null) || filled($defaults['tvl_courses'] ?? null)) {
            $this->setValueBesideLabel($sheet, 'Courses (only for TVL)', $report->tvl_courses ?? $defaults['tvl_courses'], fuzzy: true);
        }

        // Month title on the right of header block (row 8).
        $monthUpper = strtoupper($report->reportMonthLabel());
        foreach ([8] as $row) {
            for ($c = 30; $c <= 45; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$row)->getValue();
                if (is_string($val) && preg_match('/^(JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)$/i', trim($val))) {
                    $sheet->setCellValue($letter.$row, $monthUpper);
                    break 2;
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $dayMap
     */
    protected function clearLearnerArea(Worksheet $sheet, array $meta, array $dayMap): void
    {
        $rows = array_merge(
            range($meta['male_first_row'], $meta['male_last_row']),
            range($meta['female_first_row'], $meta['female_last_row']),
        );

        foreach ($rows as $r) {
            $sheet->setCellValue($meta['name_col'].$r, null);
            // Keep row numbers in column A.
            foreach ($dayMap as $letter) {
                $cell = $letter.$r;
                $this->clearMarkStyle($sheet, $cell);
                $sheet->setCellValue($cell, null);
            }
            // Leave COUNTIF formulas on ABSENT/TARDY intact when present.
            $abs = $sheet->getCell($meta['absent_col'].$r)->getValue();
            if (! is_string($abs) || ! str_starts_with($abs, '=')) {
                $sheet->setCellValue($meta['absent_col'].$r, null);
            }
            $tar = $sheet->getCell($meta['tardy_col'].$r)->getValue();
            if (! is_string($tar) || ! str_starts_with($tar, '=')) {
                $sheet->setCellValue($meta['tardy_col'].$r, null);
            }
            $sheet->setCellValue($meta['remarks_col'].$r, null);
        }
    }

    /**
     * @param  list<array{student: \App\Models\Sf2ReportStudent, marks: array<string, string>, absent_total: int, tardy_total: int}>  $rows
     * @param  array<int, string>  $dayMap
     */
    protected function fillLearnerBlock(
        Worksheet $sheet,
        array $rows,
        int $firstRow,
        int $lastRow,
        array $dayMap,
        array $meta,
    ): void {
        $maxRows = $lastRow - $firstRow + 1;
        $tz = config('sf2.timezone', 'Asia/Manila');

        foreach (array_slice($rows, 0, $maxRows) as $i => $row) {
            $r = $firstRow + $i;
            $student = $row['student'];
            $sheet->setCellValue($meta['number_col'].$r, $i + 1);
            $sheet->setCellValue($meta['name_col'].$r, $student->formattedName());

            foreach ($row['marks'] as $date => $mark) {
                try {
                    $day = (int) Carbon::parse($date, $tz)->format('j');
                } catch (\Throwable) {
                    continue;
                }
                $letter = $dayMap[$day] ?? null;
                if ($letter === null) {
                    continue;
                }
                $this->applyMark($sheet, $letter.$r, $mark);
            }

            $remarks = trim((string) $student->remarks);
            if ($remarks !== '') {
                $sheet->setCellValue($meta['remarks_col'].$r, $remarks);
            }

            // Ensure COUNTIF totals stay; if template row lacks formula, write counts.
            $absCell = $meta['absent_col'].$r;
            $tarCell = $meta['tardy_col'].$r;
            $absVal = $sheet->getCell($absCell)->getValue();
            if (! is_string($absVal) || ! str_starts_with((string) $absVal, '=')) {
                $sheet->setCellValue($absCell, $row['absent_total'] ?: null);
            }
            $tarVal = $sheet->getCell($tarCell)->getValue();
            if (! is_string($tarVal) || ! str_starts_with((string) $tarVal, '=')) {
                $sheet->setCellValue($tarCell, $row['tardy_total'] ?: null);
            }
        }
    }

    protected function fillSummary(Worksheet $sheet, Sf2Report $report, array $grid, array $meta): void
    {
        $m = count($grid['male']);
        $f = count($grid['female']);
        $days = count($report->school_days ?? []);

        // No. of Days of Classes (row ~69 near label).
        for ($r = 68; $r <= 72; $r++) {
            for ($c = 35; $c <= 42; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$r)->getValue();
                if (is_string($val) && stripos($val, 'No. of Days') !== false) {
                    $next = Coordinate::stringFromColumnIndex($c + 1);
                    // "No. of Days of Classes:" is often AK68 with value at AK69 under Month.
                    break 2;
                }
            }
        }

        // Month label + school days under SUMMARY block.
        for ($r = 68; $r <= 70; $r++) {
            for ($c = 35; $c <= 40; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$r)->getValue();
                if (is_string($val) && preg_match('/^(JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)$/i', trim($val))) {
                    $sheet->setCellValue($letter.$r, strtoupper($report->reportMonthLabel()));
                    // Value of school days is often the next column on same row.
                    $daysCol = Coordinate::stringFromColumnIndex($c + 1);
                    if (is_numeric($sheet->getCell($daysCol.$r)->getValue()) || $sheet->getCell($daysCol.$r)->getValue() === null) {
                        $sheet->setCellValue($daysCol.$r, $days);
                    }
                }
            }
        }

        // Enrolment / registered row pair: find first numeric trio under SUMMARY (AM/AN/AO style).
        $this->writeSummaryTrio($sheet, $m, $f, '* Enrolment');
        $this->writeSummaryTrio($sheet, $m, $f, 'Registered Learners as of end of the month');

        // Average Daily Attendance – approximate with present headcount (full class when no absences).
        $avgM = $m > 0 ? max(0, $m - 0) : 0;
        $avgF = $f > 0 ? max(0, $f - 0) : 0;
        // Better: average of daily present if we have totals.
        if (! empty($grid['male_daily_totals']) && $days > 0) {
            $avgM = (int) round(array_sum($grid['male_daily_totals']) / $days);
            $avgF = (int) round(array_sum($grid['female_daily_totals']) / $days);
        }
        $this->writeSummaryTrio($sheet, $avgM, $avgF, 'Average Daily Attendance');
    }

    protected function writeSummaryTrio(Worksheet $sheet, int $male, int $female, string $labelContains): void
    {
        for ($r = 69; $r <= 82; $r++) {
            for ($c = 35; $c <= 42; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$r)->getValue();
                if (! is_string($val) || stripos($val, $labelContains) === false) {
                    continue;
                }
                // Numbers sit under MALE FEMALE TOTAL headers — scan same row for first pure number or formula.
                for ($cc = $c + 1; $cc <= min($c + 8, 50); $cc++) {
                    $v = $sheet->getCellByColumnAndRow($cc, $r)->getValue();
                    if (is_numeric($v) || $v === null || $v === '') {
                        $sheet->setCellValueByColumnAndRow($cc, $r, $male);
                        $sheet->setCellValueByColumnAndRow($cc + 1, $r, $female);
                        $tot = $sheet->getCellByColumnAndRow($cc + 2, $r)->getValue();
                        if (! is_string($tot) || ! str_starts_with((string) $tot, '=')) {
                            $sheet->setCellValueByColumnAndRow($cc + 2, $r, $male + $female);
                        }

                        return;
                    }
                }
            }
        }
    }

    protected function fillSignatures(Worksheet $sheet, Sf2Report $report, array $meta): void
    {
        if ($report->teacher_name) {
            $this->setAboveLabel($sheet, 'Signature of Class Adviser', $report->teacher_name);
        }
        if ($report->school_head_name) {
            $this->setAboveLabel($sheet, 'Signature of School Head', $report->school_head_name);
        }
    }

    protected function setAboveLabel(Worksheet $sheet, string $labelContains, string $value): void
    {
        for ($r = 80; $r <= 96; $r++) {
            for ($c = 30; $c <= 45; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$r)->getValue();
                if (is_string($val) && stripos($val, $labelContains) !== false) {
                    $sheet->setCellValue($letter.($r - 1), $value);

                    return;
                }
            }
        }
    }

    protected function applyMark(Worksheet $sheet, string $cell, string $mark): void
    {
        $this->clearMarkStyle($sheet, $cell);
        $sheet->setCellValue($cell, null);

        if ($mark === Sf2GridBuilder::MARK_ABSENT) {
            $sheet->setCellValue($cell, 'X');

            return;
        }

        if ($mark === Sf2GridBuilder::MARK_TARDY) {
            // Official REMINDERS: "T" for Tardiness.
            $sheet->setCellValue($cell, 'T');
        }
        // Present stays blank (DepEd code).
    }

    protected function clearMarkStyle(Worksheet $sheet, string $cell): void
    {
        $fill = $sheet->getStyle($cell)->getFill();
        $fill->setFillType(Fill::FILL_NONE);
    }

    /**
     * Find a label on the sheet and write into the value cell to its right.
     */
    protected function setValueBesideLabel(Worksheet $sheet, string $label, mixed $value, bool $fuzzy = false): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $needle = strtolower(trim($label));

        for ($r = 1; $r <= 12; $r++) {
            for ($c = 1; $c <= 45; $c++) {
                $raw = $sheet->getCellByColumnAndRow($c, $r)->getValue();
                if (! is_string($raw)) {
                    continue;
                }
                $hay = strtolower(trim($raw));
                $match = $fuzzy
                    ? str_starts_with($hay, $needle) || str_contains($hay, $needle)
                    : ($hay === $needle || rtrim($hay) === rtrim($needle));
                if (! $match) {
                    continue;
                }

                // Prefer first non-empty existing value cell (overwrites sample text).
                $targetCol = null;
                for ($cc = $c + 1; $cc <= $c + 12; $cc++) {
                    $v = $sheet->getCellByColumnAndRow($cc, $r)->getValue();
                    if ($v === null || $v === '') {
                        if ($targetCol === null) {
                            $targetCol = $cc;
                        }
                        continue;
                    }
                    // Next label? stop before it.
                    if (is_string($v) && $this->looksLikeHeaderLabel($v)) {
                        break;
                    }
                    $targetCol = $cc;
                    break;
                }

                if ($targetCol !== null) {
                    $sheet->setCellValueByColumnAndRow($targetCol, $r, $value);

                    return;
                }
            }
        }
    }

    protected function looksLikeHeaderLabel(string $v): bool
    {
        $v = trim($v);
        static $labels = [
            'School Name', 'School ID', 'Division', 'Region', 'Semester', 'School Year',
            'Grade Level', 'Track and Strand', 'Section', 'Courses (only for TVL)',
        ];
        foreach ($labels as $label) {
            if (strcasecmp($v, $label) === 0 || str_starts_with(strtolower($v), strtolower($label))) {
                return true;
            }
        }

        return false;
    }

    protected function findColumnByRowLabel(Worksheet $sheet, int $row, string $label): ?int
    {
        $needle = strtoupper(trim($label));
        for ($c = 1; $c <= 55; $c++) {
            $v = $sheet->getCellByColumnAndRow($c, $row)->getValue();
            if (is_string($v) && strtoupper(trim($v)) === $needle) {
                return $c;
            }
        }

        return null;
    }

    protected function numericGrade(string $gradeLevel): string|int
    {
        if (preg_match('/(\d{1,2})/', $gradeLevel, $m)) {
            return (int) $m[1];
        }

        return $gradeLevel;
    }

    protected function excelFilename(Sf2Report $report): string
    {
        return $this->baseFilename($report).'.xlsx';
    }

    protected function baseFilename(Sf2Report $report): string
    {
        return sprintf(
            'SF2-SHS_%s_%s_%s_%d',
            str_replace(' ', '_', $report->grade_level),
            str_replace(' ', '_', $report->section),
            $report->reportMonthLabel(),
            $report->report_year
        );
    }
}
