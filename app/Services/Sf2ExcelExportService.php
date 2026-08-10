<?php

namespace App\Services;

use App\Models\Sf2Report;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fills official SF2 Excel workbooks:
 * - K–10: single-sheet DepEd SF2 (resources/templates/sf2/sf2-k10-template.xlsx)
 * - SHS (G11–12): multi-month SF2-SHS (resources/templates/sf2/sf2-template.xlsx)
 *
 * Summary metrics follow DepEd guidelines:
 *   % Enrolment = registered / enrolment
 *   ADA = ceil(sum of daily present weights / school days)  // half-day = 0.5 present
 *   % Attendance = ADA / registered
 */
class Sf2ExcelExportService
{
    public function __construct(
        protected Sf2GridBuilder $grid,
        protected Sf2SchoolCalendar $calendar,
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

        if ($report->usesShsTemplate()) {
            return $this->buildShsSpreadsheet($report, $grid);
        }

        return $this->buildK10Spreadsheet($report, $grid);
    }

    /**
     * @param  array<string, mixed>  $grid
     */
    protected function buildShsSpreadsheet(Sf2Report $report, array $grid): Spreadsheet
    {
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

        foreach ($spreadsheet->getSheetNames() as $title) {
            if (strcasecmp($title, $sheetName) === 0) {
                $sheetName = $title;
                break;
            }
        }

        $this->pruneSheets($spreadsheet, $sheetName);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        $dayMap = $this->rebuildShsCalendarHeaders($sheet, (int) $report->report_year, (int) $report->report_month);
        $meta = $this->detectShsLayout($sheet);

        $this->fillShsHeader($sheet, $report);
        $this->clearLearnerArea($sheet, $meta, $dayMap, withTardy: true);
        $this->fillLearnerBlock($sheet, $grid['male'], $meta['male_first_row'], $meta['male_last_row'], $dayMap, $meta, withTardy: true);
        $this->fillLearnerBlock($sheet, $grid['female'], $meta['female_first_row'], $meta['female_last_row'], $dayMap, $meta, withTardy: true);
        $this->rewriteShsDailyTotals($sheet, $meta, $dayMap, count($grid['male']), count($grid['female']));
        $this->fillShsSummary($sheet, $report, $grid);
        $this->fillSignatures($sheet, $report, 80, 96, 30, 45);

        return $spreadsheet;
    }

    /**
     * @param  array<string, mixed>  $grid
     */
    protected function buildK10Spreadsheet(Sf2Report $report, array $grid): Spreadsheet
    {
        $templatePath = config('sf2.excel.template_k10');
        if (! is_file($templatePath)) {
            throw new RuntimeException(
                'SF2 K–10 template missing. Place SCHOOL FORM 2 at resources/templates/sf2/sf2-k10-template.xlsx'
            );
        }

        $spreadsheet = IOFactory::load($templatePath);
        $cfg = config('sf2.excel.k10', []);
        $sheetName = (string) ($cfg['sheet'] ?? $spreadsheet->getSheetNames()[0]);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getSheet(0);

        $meta = $this->k10Layout($cfg);
        $dayInfo = $this->rebuildK10CalendarHeaders(
            $sheet,
            (int) $report->report_year,
            (int) $report->report_month,
            $meta
        );

        $this->fillK10Header($sheet, $report, $grid, $meta);
        $this->clearK10LearnerArea($sheet, $meta, $dayInfo['day_map']);
        $this->fillK10LearnerBlock(
            $sheet,
            $grid['male'],
            $meta['male_first_row'],
            $meta['male_last_row'],
            $dayInfo['day_map'],
            $meta
        );
        $this->fillK10LearnerBlock(
            $sheet,
            $grid['female'],
            $meta['female_first_row'],
            $meta['female_last_row'],
            $dayInfo['day_map'],
            $meta
        );
        $this->applyK10HolidayMerges($sheet, $dayInfo['holiday_cols'], $meta);
        $this->rewriteK10DailyTotals($sheet, $meta, $dayInfo['day_map'], count($grid['male']), count($grid['female']));
        $this->fillK10Summary($sheet, $report, $grid);
        $this->fillK10Signatures($sheet, $report);

        return $spreadsheet;
    }

    protected function sheetNameForMonth(int $month): string
    {
        $map = config('sf2.excel.month_sheets', []);

        return $map[$month] ?? strtoupper(Carbon::create(null, $month, 1)->format('F'));
    }

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
     * @return array<int, string> day-of-month => column letter
     */
    protected function rebuildShsCalendarHeaders(Worksheet $sheet, int $year, int $month): array
    {
        $firstCol = Coordinate::columnIndexFromString((string) config('sf2.excel.first_day_col', 'C'));
        $absentCol = $this->findColumnByRowLabel($sheet, 13, 'ABSENT')
            ?? $this->findColumnByRowLabel($sheet, 12, 'ABSENT')
            ?? Coordinate::columnIndexFromString('AJ');
        $dateRow = (int) config('sf2.excel.date_header_row', 12);
        $dowRow = (int) config('sf2.excel.dow_header_row', 13);

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
     * @param  array<string, mixed>  $meta
     * @return array{day_map: array<string, string>, holiday_cols: list<string>}
     */
    protected function rebuildK10CalendarHeaders(Worksheet $sheet, int $year, int $month, array $meta): array
    {
        $firstCol = Coordinate::columnIndexFromString($meta['first_day_col']);
        $absentIdx = Coordinate::columnIndexFromString($meta['absent_col']);
        $dateRow = $meta['date_header_row'];
        $dowRow = $meta['dow_header_row'];

        for ($c = $firstCol; $c < $absentIdx; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $sheet->setCellValue($letter.$dateRow, null);
            $sheet->setCellValue($letter.$dowRow, null);
            // Clear any leftover sample text in the day band.
            for ($r = $meta['male_first_row']; $r <= $meta['female_last_row']; $r++) {
                $sheet->setCellValue($letter.$r, null);
            }
        }

        $gridDays = $this->calendar->k10GridDaysInMonth($year, $month, $absentIdx - $firstCol);
        $tz = config('sf2.timezone', 'Asia/Manila');
        $col = $firstCol;
        $dayMap = [];
        $holidayCols = [];

        foreach ($gridDays as $info) {
            if ($col >= $absentIdx) {
                break;
            }

            $letter = Coordinate::stringFromColumnIndex($col);
            $date = Carbon::parse($info['date'], $tz);
            $sheet->setCellValue($letter.$dateRow, (int) $date->format('j'));
            $sheet->setCellValue($letter.$dowRow, $this->k10DowLabel($date));

            if (($info['type'] ?? 'school') === 'holiday') {
                $holidayCols[] = $letter;
            } else {
                $dayMap[$info['date']] = $letter;
            }

            $col++;
        }

        return ['day_map' => $dayMap, 'holiday_cols' => $holidayCols];
    }

    protected function k10DowLabel(Carbon $date): string
    {
        return match ((int) $date->dayOfWeekIso) {
            1 => 'M',
            2 => 'T',
            3 => 'W',
            4 => 'TH',
            5 => 'F',
            6 => 'S',
            7 => 'S',
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function detectShsLayout(Worksheet $sheet): array
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
            'male_total_row' => 28,
            'female_total_row' => 65,
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    protected function k10Layout(array $cfg): array
    {
        return [
            'first_day_col' => (string) ($cfg['first_day_col'] ?? 'G'),
            'date_header_row' => (int) ($cfg['date_header_row'] ?? 10),
            'dow_header_row' => (int) ($cfg['dow_header_row'] ?? 11),
            'number_col' => (string) ($cfg['number_col'] ?? 'A'),
            'name_col' => (string) ($cfg['name_col'] ?? 'B'),
            'absent_col' => (string) ($cfg['absent_col'] ?? 'AF'),
            'present_col' => (string) ($cfg['present_col'] ?? 'AG'),
            'remarks_col' => (string) ($cfg['remarks_col'] ?? 'AH'),
            'male_first_row' => (int) ($cfg['male_first_row'] ?? 13),
            'male_last_row' => (int) ($cfg['male_last_row'] ?? 42),
            'male_total_absent_row' => (int) ($cfg['male_total_absent_row'] ?? 43),
            'male_total_present_row' => (int) ($cfg['male_total_present_row'] ?? 44),
            'female_first_row' => (int) ($cfg['female_first_row'] ?? 45),
            'female_last_row' => (int) ($cfg['female_last_row'] ?? 74),
            'female_total_absent_row' => (int) ($cfg['female_total_absent_row'] ?? 75),
            'female_total_present_row' => (int) ($cfg['female_total_present_row'] ?? 76),
            'combined_total_row' => (int) ($cfg['combined_total_row'] ?? 77),
            'school_days_cell' => (string) ($cfg['school_days_cell'] ?? 'AQ9'),
            'male_count_cell' => (string) ($cfg['male_count_cell'] ?? 'AO23'),
            'female_count_cell' => (string) ($cfg['female_count_cell'] ?? 'AO24'),
        ];
    }

    protected function fillShsHeader(Worksheet $sheet, Sf2Report $report): void
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
        $this->setValueBesideLabel($sheet, 'Section', $report->section);
        if (filled($report->tvl_courses ?? null) || filled($defaults['tvl_courses'] ?? null)) {
            $this->setValueBesideLabel($sheet, 'Courses (only for TVL)', $report->tvl_courses ?? $defaults['tvl_courses'], fuzzy: true);
        }

        $monthUpper = strtoupper($report->reportMonthLabel());
        for ($c = 30; $c <= 45; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $val = $sheet->getCell($letter.'8')->getValue();
            if (is_string($val) && preg_match('/^(JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)$/i', trim($val))) {
                $sheet->setCellValue($letter.'8', $monthUpper);
                break;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $grid
     * @param  array<string, mixed>  $meta
     */
    protected function fillK10Header(Worksheet $sheet, Sf2Report $report, array $grid, array $meta): void
    {
        $defaults = config('sf2.school', []);
        $summary = $grid['summary'];
        $schoolDays = (int) $summary['school_days'];

        $sheet->setCellValue('G6', $report->school_id ?? ($defaults['school_id'] ?? ''));
        $sheet->setCellValue('G7', $report->school_name);
        $sheet->setCellValue('N6', $report->school_year);
        $sheet->setCellValue('AA6', strtoupper($report->reportMonthLabel()));
        $sheet->setCellValue('AA7', $this->numericGrade($report->grade_level));
        // Section value sits near "Section" label (AC7 on stock template; AD7 on filled sample).
        $this->setNearLabel($sheet, 7, 'Section', $report->section, 3, 10);
        $sheet->setCellValue($meta['school_days_cell'], $schoolDays);
        $sheet->setCellValue($meta['male_count_cell'], (int) $summary['male_count']);
        $sheet->setCellValue($meta['female_count_cell'], (int) $summary['female_count']);
    }

    /**
     * @param  array<int, string>|array<string, string>  $dayMap
     */
    protected function clearLearnerArea(Worksheet $sheet, array $meta, array $dayMap, bool $withTardy = true): void
    {
        $rows = array_merge(
            range($meta['male_first_row'], $meta['male_last_row']),
            range($meta['female_first_row'], $meta['female_last_row']),
        );

        foreach ($rows as $r) {
            $sheet->setCellValue($meta['name_col'].$r, null);
            foreach ($dayMap as $letter) {
                $this->clearMarkStyle($sheet, $letter.$r);
                $sheet->setCellValue($letter.$r, null);
            }
            $abs = $sheet->getCell($meta['absent_col'].$r)->getValue();
            if (! is_string($abs) || ! str_starts_with($abs, '=')) {
                $sheet->setCellValue($meta['absent_col'].$r, null);
            }
            if ($withTardy && isset($meta['tardy_col'])) {
                $tar = $sheet->getCell($meta['tardy_col'].$r)->getValue();
                if (! is_string($tar) || ! str_starts_with((string) $tar, '=')) {
                    $sheet->setCellValue($meta['tardy_col'].$r, null);
                }
            }
            if (isset($meta['remarks_col'])) {
                $sheet->setCellValue($meta['remarks_col'].$r, null);
            }
        }
    }

    /**
     * @param  array<string, string>  $dayMap
     * @param  array<string, mixed>  $meta
     */
    protected function clearK10LearnerArea(Worksheet $sheet, array $meta, array $dayMap): void
    {
        $rows = array_merge(
            range($meta['male_first_row'], $meta['male_last_row']),
            range($meta['female_first_row'], $meta['female_last_row']),
        );

        foreach ($rows as $r) {
            $sheet->setCellValue($meta['name_col'].$r, null);
            foreach ($dayMap as $letter) {
                $sheet->setCellValue($letter.$r, null);
            }
            $sheet->setCellValue($meta['remarks_col'].$r, null);
        }
    }

    /**
     * @param  list<array{student: \App\Models\Sf2ReportStudent, marks: array<string, string>, absent_total: float|int, tardy_total: int}>  $rows
     * @param  array<int, string>|array<string, string>  $dayMap
     * @param  array<string, mixed>  $meta
     */
    protected function fillLearnerBlock(
        Worksheet $sheet,
        array $rows,
        int $firstRow,
        int $lastRow,
        array $dayMap,
        array $meta,
        bool $withTardy = true,
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
                $letter = $dayMap[$day] ?? $dayMap[$date] ?? null;
                if ($letter === null) {
                    continue;
                }
                $this->applyMark($sheet, $letter.$r, $mark, shs: true);
            }

            $remarks = trim((string) $student->remarks);
            if ($remarks !== '' && isset($meta['remarks_col'])) {
                $sheet->setCellValue($meta['remarks_col'].$r, $remarks);
            }

            $absCell = $meta['absent_col'].$r;
            $absVal = $sheet->getCell($absCell)->getValue();
            if (! is_string($absVal) || ! str_starts_with((string) $absVal, '=')) {
                $sheet->setCellValue($absCell, $row['absent_total'] ?: null);
            }
            if ($withTardy && isset($meta['tardy_col'])) {
                $tarCell = $meta['tardy_col'].$r;
                $tarVal = $sheet->getCell($tarCell)->getValue();
                if (! is_string($tarVal) || ! str_starts_with((string) $tarVal, '=')) {
                    $sheet->setCellValue($tarCell, $row['tardy_total'] ?: null);
                }
            }
        }

        // Remove pre-numbered empty slots so ROWS()/COUNTA maths stay accurate.
        for ($r = $firstRow + count($rows); $r <= $lastRow; $r++) {
            $sheet->setCellValue($meta['number_col'].$r, null);
            $sheet->setCellValue($meta['name_col'].$r, null);
            foreach ($dayMap as $letter) {
                $sheet->setCellValue($letter.$r, null);
            }
            $sheet->setCellValue($meta['absent_col'].$r, null);
            if ($withTardy && isset($meta['tardy_col'])) {
                $sheet->setCellValue($meta['tardy_col'].$r, null);
            }
        }
    }

    /**
     * @param  list<array{student: \App\Models\Sf2ReportStudent, marks: array<string, string>, absent_total: float|int, tardy_total: int}>  $rows
     * @param  array<string, string>  $dayMap  Y-m-d => column letter
     * @param  array<string, mixed>  $meta
     */
    protected function fillK10LearnerBlock(
        Worksheet $sheet,
        array $rows,
        int $firstRow,
        int $lastRow,
        array $dayMap,
        array $meta,
    ): void {
        $maxRows = $lastRow - $firstRow + 1;
        $firstCol = $meta['first_day_col'];
        $lastCol = Coordinate::stringFromColumnIndex(
            Coordinate::columnIndexFromString($meta['absent_col']) - 1
        );

        foreach (array_slice($rows, 0, $maxRows) as $i => $row) {
            $r = $firstRow + $i;
            $student = $row['student'];
            $sheet->setCellValue($meta['number_col'].$r, $i + 1);
            $sheet->setCellValue($meta['name_col'].$r, $student->formattedName());

            foreach ($row['marks'] as $date => $mark) {
                $letter = $dayMap[$date] ?? null;
                if ($letter === null) {
                    continue;
                }
                $this->applyMark($sheet, $letter.$r, $mark, shs: false);
            }

            // Keep / restore template-style ABSENT / PRESENT formulas (X=1, H=0.5).
            $absCell = $meta['absent_col'].$r;
            $presCell = $meta['present_col'].$r;
            $sheet->setCellValue(
                $absCell,
                '=SUMPRODUCT(('.$firstCol.$r.':'.$lastCol.$r.'="X")*1+('.$firstCol.$r.':'.$lastCol.$r.'="H")*0.5)'
            );
            $sheet->setCellValue($presCell, '='.$meta['school_days_cell'].'-'.$absCell);

            $remarks = trim((string) $student->remarks);
            if ($remarks !== '') {
                $sheet->setCellValue($meta['remarks_col'].$r, $remarks);
            }
        }

        for ($r = $firstRow + count($rows); $r <= $lastRow; $r++) {
            $sheet->setCellValue($meta['number_col'].$r, null);
            $sheet->setCellValue($meta['name_col'].$r, null);
            foreach ($dayMap as $letter) {
                $sheet->setCellValue($letter.$r, null);
            }
            $sheet->setCellValue($meta['absent_col'].$r, null);
            $sheet->setCellValue($meta['present_col'].$r, null);
            $sheet->setCellValue($meta['remarks_col'].$r, null);
        }
    }

    /**
     * @param  list<string>  $holidayCols
     * @param  array<string, mixed>  $meta
     */
    protected function applyK10HolidayMerges(Worksheet $sheet, array $holidayCols, array $meta): void
    {
        foreach ($holidayCols as $letter) {
            $start = $meta['male_first_row'];
            $end = $meta['female_last_row'];
            $range = $letter.$start.':'.$letter.$end;

            foreach ($sheet->getMergeCells() as $merged) {
                if (str_starts_with($merged, $letter) && str_contains($merged, (string) $start)) {
                    $sheet->unmergeCells($merged);
                }
            }

            // Unmerge any existing merges intersecting this column in the learner block.
            foreach (array_keys($sheet->getMergeCells()) as $merged) {
                if (preg_match('/^'.$letter.'\d+:'.$letter.'\d+$/', $merged)) {
                    [$a, $b] = explode(':', $merged);
                    $r1 = (int) preg_replace('/\D/', '', $a);
                    $r2 = (int) preg_replace('/\D/', '', $b);
                    if ($r2 >= $start && $r1 <= $end) {
                        $sheet->unmergeCells($merged);
                    }
                }
            }

            for ($r = $start; $r <= $end; $r++) {
                $sheet->setCellValue($letter.$r, null);
            }

            $sheet->mergeCells($range);
            $sheet->setCellValue($letter.$start, 'holiday');
            $sheet->getStyle($letter.$start)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setTextRotation(90)
                ->setWrapText(true);
        }
    }

    /**
     * Restrict SHS TOTAL Per Day formulas to only rows that have learners.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $dayMap
     */
    protected function rewriteShsDailyTotals(Worksheet $sheet, array $meta, array $dayMap, int $maleCount, int $femaleCount): void
    {
        $maleStart = $meta['male_first_row'];
        $maleEnd = $maleCount > 0 ? $maleStart + $maleCount - 1 : $maleStart - 1;
        $femaleStart = $meta['female_first_row'];
        $femaleEnd = $femaleCount > 0 ? $femaleStart + $femaleCount - 1 : $femaleStart - 1;
        $maleTotalRow = (int) ($meta['male_total_row'] ?? 28);
        $femaleTotalRow = (int) ($meta['female_total_row'] ?? 65);

        foreach ($dayMap as $letter) {
            if ($maleCount > 0) {
                $sheet->setCellValue(
                    $letter.$maleTotalRow,
                    '=COUNTA('.$meta['name_col'].$maleStart.':'.$meta['name_col'].$maleEnd.')-COUNTIF('.$letter.$maleStart.':'.$letter.$maleEnd.',"X")'
                );
            } else {
                $sheet->setCellValue($letter.$maleTotalRow, 0);
            }

            if ($femaleCount > 0) {
                $sheet->setCellValue(
                    $letter.$femaleTotalRow,
                    '=COUNTA('.$meta['name_col'].$femaleStart.':'.$meta['name_col'].$femaleEnd.')-COUNTIF('.$letter.$femaleStart.':'.$letter.$femaleEnd.',"X")'
                );
            } else {
                $sheet->setCellValue($letter.$femaleTotalRow, 0);
            }
        }

        // Combined row when present.
        $combinedRow = $femaleTotalRow + 1;
        if (is_string($sheet->getCell('A'.$combinedRow)->getValue())
            && stripos((string) $sheet->getCell('A'.$combinedRow)->getValue(), 'Combined') !== false) {
            foreach ($dayMap as $letter) {
                $sheet->setCellValue($letter.$combinedRow, '=SUM('.$letter.$maleTotalRow.','.$letter.$femaleTotalRow.')');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $dayMap
     */
    protected function rewriteK10DailyTotals(Worksheet $sheet, array $meta, array $dayMap, int $maleCount, int $femaleCount): void
    {
        $maleStart = $meta['male_first_row'];
        $maleEnd = $maleCount > 0 ? $maleStart + $maleCount - 1 : $maleStart - 1;
        $femaleStart = $meta['female_first_row'];
        $femaleEnd = $femaleCount > 0 ? $femaleStart + $femaleCount - 1 : $femaleStart - 1;
        $mAbs = $meta['male_total_absent_row'];
        $mPres = $meta['male_total_present_row'];
        $fAbs = $meta['female_total_absent_row'];
        $fPres = $meta['female_total_present_row'];
        $combined = $meta['combined_total_row'];
        $maleCountCell = $meta['male_count_cell'];
        $femaleCountCell = $meta['female_count_cell'];

        foreach ($dayMap as $letter) {
            if ($maleCount > 0) {
                $sheet->setCellValue(
                    $letter.$mAbs,
                    '=SUMPRODUCT(('.$letter.$maleStart.':'.$letter.$maleEnd.'="X")*1+('.$letter.$maleStart.':'.$letter.$maleEnd.'="H")*0.5)'
                );
                $sheet->setCellValue($letter.$mPres, '=CEILING('.$maleCountCell.'-'.$letter.$mAbs.',1)');
            } else {
                $sheet->setCellValue($letter.$mAbs, 0);
                $sheet->setCellValue($letter.$mPres, 0);
            }

            if ($femaleCount > 0) {
                $sheet->setCellValue(
                    $letter.$fAbs,
                    '=SUMPRODUCT(('.$letter.$femaleStart.':'.$letter.$femaleEnd.'="X")*1+('.$letter.$femaleStart.':'.$letter.$femaleEnd.'="H")*0.5)'
                );
                $sheet->setCellValue($letter.$fPres, '=CEILING('.$femaleCountCell.'-'.$letter.$fAbs.',1)');
            } else {
                $sheet->setCellValue($letter.$fAbs, 0);
                $sheet->setCellValue($letter.$fPres, 0);
            }

            $sheet->setCellValue($letter.$combined, '='.$letter.$mPres.'+'.$letter.$fPres);
        }

        // Month totals for ABSENT / PRESENT columns.
        if ($maleCount > 0) {
            $sheet->setCellValue(
                $meta['absent_col'].$mPres,
                '=SUM('.$meta['absent_col'].$maleStart.':'.$meta['absent_col'].$maleEnd.')'
            );
            $sheet->setCellValue(
                $meta['present_col'].$mPres,
                '=SUM('.$meta['present_col'].$maleStart.':'.$meta['present_col'].$maleEnd.')'
            );
        }
        if ($femaleCount > 0) {
            $sheet->setCellValue(
                $meta['absent_col'].$fPres,
                '=SUM('.$meta['absent_col'].$femaleStart.':'.$meta['absent_col'].$femaleEnd.')'
            );
            $sheet->setCellValue(
                $meta['present_col'].$fPres,
                '=SUM('.$meta['present_col'].$femaleStart.':'.$meta['present_col'].$femaleEnd.')'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $grid
     */
    protected function fillShsSummary(Worksheet $sheet, Sf2Report $report, array $grid): void
    {
        $s = $grid['summary'];
        $m = (int) $s['male_count'];
        $f = (int) $s['female_count'];
        $days = (int) $s['school_days'];
        $adaM = (int) $s['male_ada'];
        $adaF = (int) $s['female_ada'];

        // AK69 month name, AL69 school-day count (stock SF2-SHS layout).
        for ($r = 68; $r <= 70; $r++) {
            for ($c = 35; $c <= 42; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$r)->getValue();
                if (is_string($val) && preg_match('/^(JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)$/i', trim($val))) {
                    $sheet->setCellValue($letter.$r, strtoupper($report->reportMonthLabel()));
                    $daysCol = Coordinate::stringFromColumnIndex($c + 1);
                    $sheet->setCellValue($daysCol.$r, $days);
                }
            }
        }

        $cols = $this->findSummaryGenderColumns($sheet, 68, 85);
        if ($cols === null) {
            return;
        }

        [$maleCol, $femaleCol, $totalCol] = $cols;

        $this->writeSummaryRow($sheet, 68, 85, 'Enrolment', $maleCol, $femaleCol, $totalCol, $m, $f);
        $this->writeSummaryRow($sheet, 68, 85, 'Late Enrolment', $maleCol, $femaleCol, $totalCol, 0, 0);
        $this->writeSummaryRow($sheet, 68, 85, 'Registered Learners', $maleCol, $femaleCol, $totalCol, $m, $f);
        $this->writeSummaryRow($sheet, 68, 85, 'Percentage of Enrolment', $maleCol, $femaleCol, $totalCol,
            $m > 0 ? 1 : 0, $f > 0 ? 1 : 0, asTotal: $m + $f > 0 ? 1 : 0);
        $this->writeSummaryRow($sheet, 68, 85, 'Average Daily Attendance', $maleCol, $femaleCol, $totalCol, $adaM, $adaF);
        $this->writeSummaryRow($sheet, 68, 85, 'Percentage of Attendance', $maleCol, $femaleCol, $totalCol,
            $s['male_pct_attendance'], $s['female_pct_attendance'], asTotal: $s['total_pct_attendance']);
        $this->writeSummaryRow($sheet, 68, 85, '5 consecutive', $maleCol, $femaleCol, $totalCol, 0, 0);
    }

    /**
     * @param  array<string, mixed>  $grid
     */
    protected function fillK10Summary(Worksheet $sheet, Sf2Report $report, array $grid): void
    {
        $s = $grid['summary'];
        $m = (int) $s['male_count'];
        $f = (int) $s['female_count'];
        $days = (int) $s['school_days'];
        $adaM = (int) $s['male_ada'];
        $adaF = (int) $s['female_ada'];

        // Month label in guidelines area.
        for ($r = 79; $r <= 82; $r++) {
            for ($c = 30; $c <= 40; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$r)->getValue();
                if (is_string($val) && str_starts_with(strtoupper(trim($val)), 'MONTH:')) {
                    $sheet->setCellValue($letter.$r, 'Month: '.strtoupper($report->reportMonthLabel()));
                }
                if (is_string($val) && stripos($val, 'No. of Days of Classes') !== false) {
                    $sheet->setCellValue($letter.$r, 'No. of Days of Classes: '.$days);
                }
            }
        }

        $cols = $this->findSummaryGenderColumns($sheet, 79, 100);
        if ($cols === null) {
            // Fallback to known template columns AK/AL/AM.
            $cols = [
                Coordinate::columnIndexFromString('AK'),
                Coordinate::columnIndexFromString('AL'),
                Coordinate::columnIndexFromString('AM'),
            ];
        }

        [$maleCol, $femaleCol, $totalCol] = $cols;

        $this->writeSummaryRow($sheet, 79, 100, 'Enrolment', $maleCol, $femaleCol, $totalCol, $m, $f);
        $this->writeSummaryRow($sheet, 79, 100, 'Late Enrollment', $maleCol, $femaleCol, $totalCol, 0, 0);
        $this->writeSummaryRow($sheet, 79, 100, 'Registered Learner', $maleCol, $femaleCol, $totalCol, $m, $f);
        $this->writeSummaryRow($sheet, 79, 100, 'Percentage of Enrolment', $maleCol, $femaleCol, $totalCol,
            $m > 0 ? 1 : 0, $f > 0 ? 1 : 0, asTotal: $m + $f > 0 ? 1 : 0);
        $this->writeSummaryRow($sheet, 79, 100, 'Average Daily Attendance', $maleCol, $femaleCol, $totalCol, $adaM, $adaF);
        $this->writeSummaryRow($sheet, 79, 100, 'Percentage of Attendance', $maleCol, $femaleCol, $totalCol,
            $s['male_pct_attendance'], $s['female_pct_attendance'], asTotal: $s['total_pct_attendance']);
        $this->writeSummaryRow($sheet, 79, 100, '5 consecutive', $maleCol, $femaleCol, $totalCol, 0, 0);
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    protected function findSummaryGenderColumns(Worksheet $sheet, int $rowFrom, int $rowTo): ?array
    {
        for ($r = $rowFrom; $r <= $rowTo; $r++) {
            $maleCol = $femaleCol = $totalCol = null;
            for ($c = 30; $c <= 55; $c++) {
                $v = $sheet->getCellByColumnAndRow($c, $r)->getValue();
                if (! is_string($v)) {
                    continue;
                }
                $t = strtoupper(trim($v));
                if ($t === 'MALE' || $t === 'M') {
                    $maleCol = $c;
                } elseif ($t === 'FEMALE' || $t === 'F') {
                    $femaleCol = $c;
                } elseif ($t === 'TOTAL') {
                    $totalCol = $c;
                }
            }
            if ($maleCol && $femaleCol && $totalCol) {
                return [$maleCol, $femaleCol, $totalCol];
            }
        }

        return null;
    }

    protected function writeSummaryRow(
        Worksheet $sheet,
        int $rowFrom,
        int $rowTo,
        string $labelContains,
        int $maleCol,
        int $femaleCol,
        int $totalCol,
        float|int $male,
        float|int $female,
        float|int|null $asTotal = null,
    ): void {
        for ($r = $rowFrom; $r <= $rowTo; $r++) {
            for ($c = 30; $c <= 50; $c++) {
                $val = $sheet->getCellByColumnAndRow($c, $r)->getValue();
                if (! is_string($val) || stripos($val, $labelContains) === false) {
                    continue;
                }

                $sheet->setCellValueByColumnAndRow($maleCol, $r, $male);
                $sheet->setCellValueByColumnAndRow($femaleCol, $r, $female);
                $sheet->setCellValueByColumnAndRow($totalCol, $r, $asTotal ?? ($male + $female));

                return;
            }
        }
    }

    protected function fillSignatures(Worksheet $sheet, Sf2Report $report, int $rFrom, int $rTo, int $cFrom, int $cTo): void
    {
        if ($report->teacher_name) {
            $this->setAboveLabel($sheet, 'Signature of Class Adviser', $report->teacher_name, $rFrom, $rTo, $cFrom, $cTo);
        }
        if ($report->school_head_name) {
            $this->setAboveLabel($sheet, 'Signature of School Head', $report->school_head_name, $rFrom, $rTo, $cFrom, $cTo);
        }
    }

    protected function fillK10Signatures(Worksheet $sheet, Sf2Report $report): void
    {
        if ($report->teacher_name) {
            // Adviser name sits above the signature line near AE104 area.
            for ($r = 100; $r <= 108; $r++) {
                for ($c = 30; $c <= 40; $c++) {
                    $letter = Coordinate::stringFromColumnIndex($c);
                    $val = $sheet->getCell($letter.$r)->getValue();
                    if (is_string($val) && stripos($val, 'Signature of Adviser') !== false) {
                        $sheet->setCellValue($letter.($r - 2), $report->teacher_name);

                        return;
                    }
                }
            }
        }
        if ($report->school_head_name) {
            for ($r = 100; $r <= 108; $r++) {
                for ($c = 30; $c <= 40; $c++) {
                    $letter = Coordinate::stringFromColumnIndex($c);
                    $val = $sheet->getCell($letter.$r)->getValue();
                    if (is_string($val) && stripos($val, 'Signature of School Head') !== false) {
                        $sheet->setCellValue($letter.($r - 1), $report->school_head_name);

                        return;
                    }
                }
            }
        }
    }

    protected function setAboveLabel(
        Worksheet $sheet,
        string $labelContains,
        string $value,
        int $rFrom = 80,
        int $rTo = 96,
        int $cFrom = 30,
        int $cTo = 45,
    ): void {
        for ($r = $rFrom; $r <= $rTo; $r++) {
            for ($c = $cFrom; $c <= $cTo; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($letter.$r)->getValue();
                if (is_string($val) && stripos($val, $labelContains) !== false) {
                    $sheet->setCellValue($letter.($r - 1), $value);

                    return;
                }
            }
        }
    }

    protected function applyMark(Worksheet $sheet, string $cell, string $mark, bool $shs = true): void
    {
        $this->clearMarkStyle($sheet, $cell);
        $sheet->setCellValue($cell, null);

        if ($mark === Sf2GridBuilder::MARK_ABSENT) {
            $sheet->setCellValue($cell, 'X');

            return;
        }

        if ($mark === Sf2GridBuilder::MARK_TARDY) {
            $sheet->setCellValue($cell, 'T');

            return;
        }

        if (! $shs && $mark === Sf2GridBuilder::MARK_HALF) {
            $sheet->setCellValue($cell, 'H');
        }
        // Present stays blank.
    }

    protected function clearMarkStyle(Worksheet $sheet, string $cell): void
    {
        $fill = $sheet->getStyle($cell)->getFill();
        $fill->setFillType(Fill::FILL_NONE);
    }

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

                $targetCol = null;
                for ($cc = $c + 1; $cc <= $c + 12; $cc++) {
                    $v = $sheet->getCellByColumnAndRow($cc, $r)->getValue();
                    if ($v === null || $v === '') {
                        if ($targetCol === null) {
                            $targetCol = $cc;
                        }
                        continue;
                    }
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

    protected function setNearLabel(Worksheet $sheet, int $row, string $label, mixed $value, int $scanMin = 1, int $scanMax = 15): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $needle = strtolower(trim($label));
        for ($c = 1; $c <= 40; $c++) {
            $raw = $sheet->getCellByColumnAndRow($c, $row)->getValue();
            if (! is_string($raw) || ! str_contains(strtolower($raw), $needle)) {
                continue;
            }
            // Prefer the first empty cell to the right of the label.
            for ($cc = $c + 1; $cc <= min(45, $c + $scanMax); $cc++) {
                $v = $sheet->getCellByColumnAndRow($cc, $row)->getValue();
                if ($v === null || $v === '') {
                    $sheet->setCellValueByColumnAndRow($cc, $row, $value);

                    return;
                }
                if (is_string($v) && $this->looksLikeHeaderLabel($v)) {
                    continue;
                }
                // Overwrite adjacent sample value once.
                $sheet->setCellValueByColumnAndRow($cc, $row, $value);

                return;
            }
        }
    }

    protected function looksLikeHeaderLabel(string $v): bool
    {
        $v = trim($v);
        static $labels = [
            'School Name', 'School ID', 'Division', 'Region', 'Semester', 'School Year',
            'Grade Level', 'Track and Strand', 'Section', 'Courses (only for TVL)',
            'Name of School', 'Report for the',
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

        if (stripos($gradeLevel, 'kinder') !== false) {
            return 'K';
        }

        return $gradeLevel;
    }

    protected function excelFilename(Sf2Report $report): string
    {
        $prefix = $report->usesShsTemplate() ? 'SF2-SHS' : 'SF2';

        return sprintf(
            '%s_%s_%s_%s_%d.xlsx',
            $prefix,
            str_replace(' ', '_', $report->grade_level),
            str_replace(' ', '_', $report->section),
            $report->reportMonthLabel(),
            $report->report_year
        );
    }
}
