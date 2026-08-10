<?php

namespace App\Imports;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\Student;
use App\Models\User;
use App\Support\AdvisoryScope;
use App\Support\StudentNameParser;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Update-only import: mobile + emergency contact fields on existing students.
 * Supports clean templates (header row 1) and multi-sheet section rosters
 * (headers mid-sheet, MALE/FEMALE dividers, "Grade 7 - St. Agnes" titles).
 */
class StudentsContactImport
{
    public int $updated = 0;

    public int $skipped = 0;

    public int $unchanged = 0;

    /** @var list<string> */
    public array $notFound = [];

    /** @var list<string> */
    public array $ambiguous = [];

    /** @var list<string> */
    public array $noContactData = [];

    /** @var list<string> */
    public array $outOfScope = [];

    public function __construct(public ?User $actor = null)
    {
        $this->actor = $actor ?? auth()->user();
    }

    public function importFile(string|UploadedFile $file): void
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $spreadsheet = IOFactory::load($path);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            $this->processSheet((string) $sheet->getTitle(), $rows);
        }
    }

    /** @return array{updated:int,skipped:int,unchanged:int,not_found:list<string>,ambiguous:list<string>,no_contact_data:list<string>} */
    public function report(): array
    {
        return [
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'unchanged' => $this->unchanged,
            'not_found' => $this->notFound,
            'ambiguous' => $this->ambiguous,
            'no_contact_data' => $this->noContactData,
            'out_of_scope' => $this->outOfScope,
        ];
    }

    /**
     * @param  list<list<mixed|null>>  $rows
     */
    private function processSheet(string $sheetTitle, array $rows): void
    {
        [$sheetYear, $sheetSection] = $this->parseYearSection($sheetTitle);
        $contextYear = $sheetYear;
        $contextSection = $sheetSection;

        /** @var array<string,int>|null $map field => column index */
        $map = null;

        foreach ($rows as $index => $row) {
            $line = $sheetTitle.' row '.($index + 1);
            $cells = $this->normalizeCells($row);

            if ($this->rowIsBlank($cells)) {
                continue;
            }

            if ($this->looksLikeHeaderRow($cells)) {
                $map = $this->buildColumnMap($cells);

                continue;
            }

            if ($this->looksLikeSexDivider($cells) || $this->looksLikeMetaRow($cells)) {
                continue;
            }

            $sectionTitle = $this->detectSectionTitle($cells);
            if ($sectionTitle !== null) {
                [$contextYear, $contextSection] = $this->parseYearSection($sectionTitle);
                if ($contextYear === null && $sheetYear !== null) {
                    $contextYear = $sheetYear;
                }
                if ($contextSection === null && $sheetSection !== null) {
                    $contextSection = $sheetSection;
                }

                continue;
            }

            if ($map === null) {
                // Fallback: treat first non-meta row keys as already named (unlikely without header).
                continue;
            }

            $fields = $this->extractFields($cells, $map, $contextYear, $contextSection);
            $payload = $this->contactPayload($fields);

            if ($payload === []) {
                // Skip pure empty contact rows silently (roster spacers / incomplete lines).
                if ($this->hasIdentifier($fields)) {
                    $this->noContactData[] = $line.': no contact fields to update ('.$this->identifierLabel($fields).')';
                    $this->skipped++;
                }

                continue;
            }

            if (! $this->hasIdentifier($fields)) {
                $this->skipped++;

                continue;
            }

            $match = $this->findStudent($fields);

            if ($match['status'] === 'not_found') {
                $this->notFound[] = $line.': no student matched ('.$this->identifierLabel($fields).')';
                $this->skipped++;

                continue;
            }

            if ($match['status'] === 'ambiguous') {
                $this->ambiguous[] = $line.': multiple students matched ('.$this->identifierLabel($fields).') — '.$match['detail'];
                $this->skipped++;

                continue;
            }

            /** @var Student $student */
            $student = $match['student'];

            if (! AdvisoryScope::canMutateStudent($student, $this->actor)) {
                $this->outOfScope[] = $line.': outside your grade access ('.$student->year.' · '.$student->section.')';
                $this->skipped++;

                continue;
            }

            $changes = [];

            foreach ($payload as $column => $value) {
                $current = trim((string) ($student->{$column} ?? ''));
                if ($current !== $value) {
                    $changes[$column] = $value;
                }
            }

            if ($changes === []) {
                $this->unchanged++;

                continue;
            }

            $student->update($changes);
            $this->updated++;
        }
    }

    /**
     * @param  list<mixed|null>  $row
     * @return list<string>
     */
    private function normalizeCells(array $row): array
    {
        $cells = [];
        foreach (array_values($row) as $value) {
            if ($value === null) {
                $cells[] = '';

                continue;
            }

            if (is_float($value) || is_int($value)) {
                // Avoid scientific notation for IDs / LRN / phones stored as numbers.
                $cells[] = trim(sprintf('%.0f', $value));

                continue;
            }

            $cells[] = trim((string) $value);
        }

        return $cells;
    }

    /** @param  list<string>  $cells */
    private function rowIsBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $cells */
    private function looksLikeHeaderRow(array $cells): bool
    {
        $joined = mb_strtolower(implode(' | ', $cells));

        $hasName = (bool) preg_match('/\bname\b/', $joined);
        $hasIdish = (bool) preg_match('/\b(id\s*num|id\s*number|student\s*id|lrn|rfid)\b/', $joined);
        $hasContact = (bool) preg_match('/\b(mobile|emergency|relationship|contact)\b/', $joined);

        return $hasName && ($hasIdish || $hasContact);
    }

    /**
     * @param  list<string>  $cells
     * @return array<string,int>
     */
    private function buildColumnMap(array $cells): array
    {
        $map = [];

        foreach ($cells as $index => $header) {
            $field = $this->mapHeaderToField($header);
            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    private function mapHeaderToField(string $header): ?string
    {
        $h = mb_strtolower(trim(preg_replace('/\s+/', ' ', $header) ?? ''));
        if ($h === '') {
            return null;
        }

        // Order matters: more specific before generic.
        return match (true) {
            str_contains($h, 'emergency') && str_contains($h, 'address') => 'emergency_address',
            str_contains($h, 'emergency') && (str_contains($h, 'number') || str_contains($h, 'contact'))
                && ! str_contains($h, 'person') && ! str_contains($h, 'to be') => 'emergency_number',
            (str_contains($h, 'emergency') && (str_contains($h, 'person') || str_contains($h, 'contacted')))
                || $h === 'guardian' || $h === 'guardian name' => 'emergency_person',
            $h === 'relationship' || str_contains($h, 'relationship') => 'emergency_relationship',
            str_contains($h, 'student mobile') || $h === 'mobile number' || $h === 'mobile'
                || $h === 'student mobile number' || $h === 'contact number' && ! str_contains($h, 'emergency')
                || $h === 'cellphone' || $h === 'phone' => 'mobile_number',
            $h === 'name' || $h === 'student name' || $h === 'fullname' || $h === 'full name' => 'name',
            $h === 'idnum' || $h === 'id num' || $h === 'id number' || $h === 'student id'
                || $h === 'student_id' || $h === 'id no' || $h === 'id no.' => 'student_id',
            str_starts_with($h, 'lrn') => 'lrn',
            $h === 'rfid' || str_contains($h, 'rfid') => 'rfid',
            $h === 'year' || $h === 'grade' || $h === 'level' || $h === 'grade level' => 'year',
            $h === 'section' || $h === 'homeroom' => 'section',
            default => null,
        };
    }

    /** @param  list<string>  $cells */
    private function looksLikeSexDivider(array $cells): bool
    {
        $nonEmpty = array_values(array_filter($cells, fn (string $c) => $c !== ''));
        if (count($nonEmpty) !== 1) {
            return false;
        }

        return in_array(mb_strtoupper($nonEmpty[0]), ['MALE', 'FEMALE', 'BOYS', 'GIRLS'], true);
    }

    /** @param  list<string>  $cells */
    private function looksLikeMetaRow(array $cells): bool
    {
        $joined = mb_strtolower(implode(' ', array_filter($cells)));

        return str_contains($joined, 'adviser')
            || str_contains($joined, 'no. of pupils')
            || str_contains($joined, 'no. of students')
            || str_contains($joined, 'number of pupils')
            || str_contains($joined, 'number of students');
    }

    /** @param  list<string>  $cells */
    private function detectSectionTitle(array $cells): ?string
    {
        $nonEmpty = array_values(array_filter($cells, fn (string $c) => $c !== ''));
        if ($nonEmpty === []) {
            return null;
        }

        // Prefer a single label cell like "Grade 7 - St. Agnes"
        $candidate = $nonEmpty[0];
        if (count($nonEmpty) > 2) {
            return null;
        }

        if (preg_match('/^(grade|kinder|nursery)\b/i', $candidate)) {
            return $candidate;
        }

        // Sheet-style titles sometimes appear as "7 - ST. AGNES"
        if (preg_match('/^\d{1,2}\s*[-–]\s*.+/', $candidate)) {
            return $candidate;
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?string} [year, section]
     */
    private function parseYearSection(string $label): array
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
        if ($label === '') {
            return [null, null];
        }

        // "Grade 7 - St. Agnes" | "Grade 7 – St. Agnes"
        if (preg_match('/^(Grade\s*(?:1[0-2]|[1-9])|Kinder(?:\s*[12])?)\s*[-–]\s*(.+)$/i', $label, $m)) {
            return [$this->normalizeYearLabel($m[1]), trim($m[2])];
        }

        // "7 - ST. AGNES" / "10 - ST. MARK"
        if (preg_match('/^(1[0-2]|[1-9])\s*[-–]\s*(.+)$/', $label, $m)) {
            return ['Grade '.$m[1], trim($m[2])];
        }

        if (preg_match('/^(Grade\s*(?:1[0-2]|[1-9])|Kinder(?:\s*[12])?)$/i', $label, $m)) {
            return [$this->normalizeYearLabel($m[1]), null];
        }

        return [null, null];
    }

    private function normalizeYearLabel(string $year): string
    {
        $year = trim(preg_replace('/\s+/', ' ', $year) ?? '');
        if (preg_match('/^kinder/i', $year)) {
            return 'Kinder';
        }
        if (preg_match('/grade\s*(1[0-2]|[1-9])/i', $year, $m)) {
            return 'Grade '.$m[1];
        }

        return $year;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string,int>  $map
     * @return array<string,string>
     */
    private function extractFields(array $cells, array $map, ?string $year, ?string $section): array
    {
        $get = function (string $field) use ($cells, $map): string {
            if (! isset($map[$field])) {
                return '';
            }
            $idx = $map[$field];

            return isset($cells[$idx]) ? trim((string) $cells[$idx]) : '';
        };

        $fields = [
            'name' => $get('name'),
            'student_id' => $get('student_id'),
            'lrn' => $get('lrn'),
            'rfid' => $get('rfid'),
            'year' => $get('year') ?: (string) ($year ?? ''),
            'section' => $get('section') ?: (string) ($section ?? ''),
            'mobile_number' => $this->normalizePhone($get('mobile_number')),
            'emergency_person' => $this->cleanText($get('emergency_person')),
            'emergency_relationship' => $this->cleanText($get('emergency_relationship')),
            'emergency_number' => $this->normalizePhone($get('emergency_number'), allowMultiple: true),
            'emergency_address' => $this->cleanText($get('emergency_address')),
        ];

        // Strip row counter / blank name noise
        if (in_array(mb_strtoupper($fields['name']), ['MALE', 'FEMALE', 'NAME', 'BOYS', 'GIRLS'], true)) {
            $fields['name'] = '';
        }

        return $fields;
    }

    private function cleanText(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '' || preg_match('/^(n\/?a|none|null|-+|\.+)$/i', $value)) {
            return '';
        }

        return $value;
    }

    private function normalizePhone(string $raw, bool $allowMultiple = false): string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^(n\/?a|none|null|-+|\.+)$/i', $raw)) {
            return '';
        }

        // Dual numbers like "0977…/0977…" — keep first primary, optionally full string if short enough.
        if ($allowMultiple && preg_match('/[\/,;&]/', $raw)) {
            $parts = preg_split('/\s*[\/,;&]\s*/', $raw) ?: [];
            $normalized = [];
            foreach ($parts as $part) {
                $n = $this->normalizeSinglePhone($part);
                if ($n !== '') {
                    $normalized[] = $n;
                }
            }
            if ($normalized === []) {
                return '';
            }
            $joined = implode(' / ', $normalized);

            // Fit common SMS/UI constraints while keeping both numbers when possible.
            return mb_strlen($joined) <= 40 ? $joined : $normalized[0];
        }

        return $this->normalizeSinglePhone($raw);
    }

    private function normalizeSinglePhone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Keep leading + if present; otherwise digits only for normalization attempt.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }

        // 639XXXXXXXXX -> 09XXXXXXXXX
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        // 9XXXXXXXXX (10 digits) -> 09XXXXXXXXX
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        // already 09XXXXXXXXX
        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            return $digits;
        }

        return $digits;
    }

    /**
     * @param  array<string,string>  $fields
     * @return array<string,string>
     */
    private function contactPayload(array $fields): array
    {
        $payload = [];
        foreach (['mobile_number', 'emergency_person', 'emergency_relationship', 'emergency_number', 'emergency_address'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                $payload[$key] = $fields[$key];
            }
        }

        return $payload;
    }

    /** @param  array<string,string>  $fields */
    private function hasIdentifier(array $fields): bool
    {
        foreach (['student_id', 'lrn', 'rfid', 'name'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string,string>  $fields */
    private function identifierLabel(array $fields): string
    {
        foreach (['student_id', 'lrn', 'rfid', 'name'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                return $key.'='.$fields[$key];
            }
        }

        return 'no identifier';
    }

    /**
     * @param  array<string,string>  $fields
     * @return array{status:string,student?:Student,detail?:string}
     */
    private function findStudent(array $fields): array
    {
        $studentId = $fields['student_id'] ?? '';
        if ($studentId !== '') {
            $student = Student::where('student_id', $studentId)->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
            $baseId = preg_replace('/-\d+$/', '', $studentId) ?? $studentId;
            if ($baseId !== $studentId) {
                $student = Student::where('student_id', $baseId)->first();
                if ($student) {
                    return ['status' => 'ok', 'student' => $student];
                }
            }
        }

        $lrn = $fields['lrn'] ?? '';
        if ($lrn !== '') {
            $student = Student::where('lrn', $lrn)->first()
                ?? Student::where('lrn', ltrim($lrn, '0'))->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
        }

        $rfid = $fields['rfid'] ?? '';
        if ($rfid !== '') {
            $student = Student::where('rfid', $rfid)->first()
                ?? Student::where('rfid', ltrim($rfid, '0'))->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
        }

        return $this->findByName($fields);
    }

    /**
     * @param  array<string,string>  $fields
     * @return array{status:string,student?:Student,detail?:string}
     */
    private function findByName(array $fields): array
    {
        $name = trim($fields['name'] ?? '');
        if ($name === '') {
            return ['status' => 'not_found'];
        }

        $parsed = StudentNameParser::parse($name);
        $year = trim($fields['year'] ?? '');
        $section = trim($fields['section'] ?? '');

        $query = Student::query();

        if ($parsed) {
            $query->whereRaw('LOWER(lastname) = ?', [mb_strtolower($parsed['lastname'])])
                ->where(function ($q) use ($parsed) {
                    $first = mb_strtolower($parsed['firstname']);
                    $q->whereRaw('LOWER(firstname) = ?', [$first])
                        ->orWhereRaw('LOWER(firstname) LIKE ?', [$first.' %']);
                });
        } else {
            $normalized = NormalizeStudentNames::normalizeFullName($name);
            if ($normalized === '' || $normalized === null) {
                return ['status' => 'not_found'];
            }
            $query->where('normalized_name', $normalized);
        }

        if ($year !== '') {
            $query->whereRaw('LOWER(year) = ?', [mb_strtolower($year)]);
        }
        if ($section !== '') {
            $query->whereRaw('LOWER(COALESCE(section, "")) = ?', [mb_strtolower($section)]);
        }

        $matches = $query->limit(5)->get();

        if ($matches->count() === 1) {
            return ['status' => 'ok', 'student' => $matches->first()];
        }

        // Retry without year/section if over-constrained (section names often differ slightly).
        if ($matches->isEmpty() && ($year !== '' || $section !== '')) {
            $retry = Student::query();
            if ($parsed) {
                $retry->whereRaw('LOWER(lastname) = ?', [mb_strtolower($parsed['lastname'])])
                    ->where(function ($q) use ($parsed) {
                        $first = mb_strtolower($parsed['firstname']);
                        $q->whereRaw('LOWER(firstname) = ?', [$first])
                            ->orWhereRaw('LOWER(firstname) LIKE ?', [$first.' %']);
                    });
            } else {
                $normalized = NormalizeStudentNames::normalizeFullName($name);
                $retry->where('normalized_name', $normalized);
            }
            $matches = $retry->limit(5)->get();
            if ($matches->count() === 1) {
                return ['status' => 'ok', 'student' => $matches->first()];
            }
        }

        // Section fuzzy: sheet "ST. AGNES" vs DB "St. Agnes" already handled by LOWER.
        // Try stripping "St." / "Saint" variants if still empty/ambiguous with section.
        if (($matches->isEmpty() || $matches->count() > 1) && $section !== '') {
            $fuzzy = Student::query();
            if ($parsed) {
                $fuzzy->whereRaw('LOWER(lastname) = ?', [mb_strtolower($parsed['lastname'])])
                    ->where(function ($q) use ($parsed) {
                        $first = mb_strtolower($parsed['firstname']);
                        $q->whereRaw('LOWER(firstname) = ?', [$first])
                            ->orWhereRaw('LOWER(firstname) LIKE ?', [$first.' %']);
                    });
            } else {
                $normalized = NormalizeStudentNames::normalizeFullName($name);
                $fuzzy->where('normalized_name', $normalized);
            }
            if ($year !== '') {
                $fuzzy->whereRaw('LOWER(year) = ?', [mb_strtolower($year)]);
            }
            $sectionNeedle = $this->sectionNeedle($section);
            if ($sectionNeedle !== '') {
                $fuzzy->whereRaw('LOWER(COALESCE(section, "")) LIKE ?', ['%'.$sectionNeedle.'%']);
            }
            $fuzzyMatches = $fuzzy->limit(5)->get();
            if ($fuzzyMatches->count() === 1) {
                return ['status' => 'ok', 'student' => $fuzzyMatches->first()];
            }
            if ($fuzzyMatches->isNotEmpty()) {
                $matches = $fuzzyMatches;
            }
        }

        if ($matches->isEmpty()) {
            return ['status' => 'not_found'];
        }

        if ($matches->count() === 1) {
            return ['status' => 'ok', 'student' => $matches->first()];
        }

        $detail = $matches->map(fn (Student $s) => '#'.$s->id.' '.$s->lastname.', '.$s->firstname)->implode('; ');

        return ['status' => 'ambiguous', 'detail' => $detail];
    }

    private function sectionNeedle(string $section): string
    {
        $s = mb_strtolower(trim($section));
        $s = preg_replace('/\b(st\.?|saint)\b/i', '', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');

        return $s;
    }
}
