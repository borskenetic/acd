<?php

namespace App\Imports;

use App\Console\Commands\NormalizeStudentNames;
use App\Enums\EducationalLevel;
use App\Models\Student;
use App\Support\PatronOptions;
use App\Support\StudentNameParser;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithValidation;

class StudentsImport implements ToModel, WithHeadingRow, WithMapping, SkipsEmptyRows, WithValidation
{
    public function map($row): array
    {
        $recordId = $this->pick($row, 'recordid', 'record_id');
        $studentId = $this->pick($row, 'idnum', 'id_num', 'student_id', 'id_number');
        $lrn = $this->pickLrn($row);
        $firstname = $this->pick($row, 'firstname', 'first_name');
        $lastname = $this->pick($row, 'lastname', 'last_name');
        $middleName = $this->pick($row, 'middlename', 'middle_name', 'middle_initial');

        // Combined NAME column (e.g. "ALLOSO, Tobias APOSTOL") when separate name columns are blank.
        if ($firstname === '' || $lastname === '') {
            $combined = $this->pick($row, 'name', 'full_name', 'fullname_name', 'student_name');
            if ($combined !== '') {
                $parsed = StudentNameParser::parse($combined);
                if ($parsed !== null) {
                    if ($lastname === '') {
                        $lastname = $parsed['lastname'];
                    }
                    if ($firstname === '') {
                        $firstname = $parsed['firstname'];
                    }
                    if ($middleName === '' && $parsed['middle_initial'] !== null) {
                        $middleName = $parsed['middle_initial'];
                    }
                }
            }
        }

        $sectionRaw = $this->pick($row, 'section', 'homeroom', 'homeroom_section');
        $gradeLevel = $this->pick($row, 'year', 'grade', 'gradelevel', 'grade_level');
        $courseStrand = $this->pick($row, 'coursestrand', 'course_strand', 'course');

        $year = $this->matchYearLabel($gradeLevel) ?? ($gradeLevel !== '' ? $gradeLevel : null);
        $section = $sectionRaw !== '' ? $sectionRaw : null;
        $levelHint = trim(($year ?? '').' '.$gradeLevel.' '.$courseStrand);

        return [
            'record_id' => $recordId !== '' ? $recordId : null,
            'student_id' => $studentId !== '' ? $studentId : null,
            'lrn' => $lrn,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'middle_initial' => $middleName !== '' ? $middleName : null,
            'birth_date' => $this->parseDate($this->pick($row, 'birthday', 'birth_date', 'birth_date')),
            'year' => $year,
            'section' => $section,
            'course' => $courseStrand !== '' ? $courseStrand : null,
            'educational_level' => $this->inferEducationalLevel($levelHint),
            'emergency_person' => $this->pick($row, 'guardianname', 'guardian_name', 'emergency_person') ?: null,
            'emergency_address' => $this->pick($row, 'guardianaddress', 'guardian_address', 'emergency_address') ?: null,
            'emergency_number' => $this->pick($row, 'guardiancontact', 'guardian_contact', 'emergency_number', 'mobile_number') ?: null,
            'qrcode' => $this->pick($row, 'qrcode') ?: null,
            'rfid' => $this->pick($row, 'rfid', 'rfid_code', 'card_number', 'card_no') ?: null,
        ];
    }

    public function rules(): array
    {
        return [
            '*.student_id' => 'nullable|max:255|unique:students,student_id',
            '*.firstname' => 'required|string|max:255',
            '*.lastname' => 'required|string|max:255',
            '*.lrn' => 'nullable|unique:students,lrn|max:32',
            '*.record_id' => 'nullable|unique:students,record_id',
            '*.qrcode' => 'nullable|unique:students,qrcode',
            '*.rfid' => 'nullable|unique:students,rfid',
            '*.educational_level' => ['nullable', Rule::in(EducationalLevel::values())],
        ];
    }

    public function model(array $row)
    {
        $studentId = trim((string) ($row['student_id'] ?? ''));
        $firstname = trim((string) ($row['firstname'] ?? ''));
        $lastname = trim((string) ($row['lastname'] ?? ''));

        if ($firstname === '' || $lastname === '') {
            return null;
        }

        $qrcode = trim((string) ($row['qrcode'] ?? ''));
        if ($qrcode === '') {
            $qrcode = $this->nextStudentQrCode();
        }

        $level = trim((string) ($row['educational_level'] ?? ''));
        if ($level === '' || ! in_array($level, EducationalLevel::values(), true)) {
            $level = EducationalLevel::College->value;
        }

        $lrn = trim((string) ($row['lrn'] ?? ''));

        return new Student([
            'record_id' => $row['record_id'] ?? null,
            'student_id' => $studentId !== '' ? $studentId : null,
            'lrn' => $lrn !== '' ? $lrn : null,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'middle_initial' => $row['middle_initial'] ?? null,
            'birth_date' => $row['birth_date'] ?? null,
            'year' => $row['year'] ?? null,
            'section' => $row['section'] ?? null,
            'course' => $row['course'] ?? null,
            'educational_level' => $level,
            'emergency_person' => $row['emergency_person'] ?? null,
            'emergency_address' => $row['emergency_address'] ?? null,
            'emergency_number' => $row['emergency_number'] ?? null,
            'qrcode' => $qrcode,
            'rfid' => trim((string) ($row['rfid'] ?? '')) ?: null,
            'normalized_name' => NormalizeStudentNames::normalizeFullName($firstname.' '.$lastname),
        ]);
    }

    private function matchYearLabel(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }

        $compact = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        foreach (PatronOptions::allYearOptions() as $option) {
            if (strtolower(preg_replace('/\s+/', '', $option) ?? '') === $compact) {
                return $option;
            }
        }

        if (preg_match('/\bkinder\s*([12])\b/i', $value, $m)) {
            return 'Kinder';
        }

        if (preg_match('/\bgrade\s*(1[0-2]|[1-9])\b/i', $value, $m)) {
            return 'Grade '.$m[1];
        }

        if (preg_match('/\bkinder\b/i', $value) || preg_match('/\bk\s*([12])\b/i', $value)) {
            return 'Kinder';
        }

        return null;
    }

    private function pickLrn(array $row): ?string
    {
        foreach (['lrn', 'learner_reference_number', 'learners_reference_number'] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $raw = $row[$key];
            if ($raw === null || $raw === '') {
                continue;
            }

            if (is_numeric($raw)) {
                // Avoid scientific notation / lost leading zeros from Excel numbers.
                $digits = preg_replace('/\D/', '', sprintf('%.0f', (float) $raw)) ?? '';

                return $digits !== '' ? $digits : null;
            }

            $digits = preg_replace('/\s+/', '', trim((string) $raw)) ?? '';

            return $digits !== '' ? $digits : null;
        }

        return null;
    }

    private function pick(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function inferEducationalLevel(string $haystack): string
    {
        $haystack = strtolower(trim($haystack));

        if ($haystack === '') {
            return EducationalLevel::College->value;
        }

        if (preg_match('/\bkinder\b|\bk\s*[12]\b|\bgrade\s*[1-6]\b|\bgrades?\s*[1-6]\b/', $haystack)) {
            return EducationalLevel::GradeSchool->value;
        }

        if (preg_match('/\bgrade\s*(7|8|9|10)\b|\bgrades?\s*(7|8|9|10)\b/', $haystack)) {
            return EducationalLevel::HighSchoolJunior->value;
        }

        if (preg_match('/\bgrade\s*(11|12)\b|\bgrades?\s*(11|12)\b|\bsenior\s*high\b/', $haystack)) {
            return EducationalLevel::HighSchoolSenior->value;
        }

        if (preg_match('/\bcollege\b|\b1st\s*year\b|\b2nd\s*year\b|\b3rd\s*year\b|\b4th\s*year\b|\bbsc\b|\bba\b|\bstrand\b/', $haystack)) {
            return EducationalLevel::College->value;
        }

        return EducationalLevel::College->value;
    }

    private function nextStudentQrCode(): string
    {
        $last = Student::whereNotNull('qrcode')
            ->where('qrcode', 'like', 'S-%')
            ->orderByDesc('id')
            ->value('qrcode');

        $nextNumber = 1;
        if ($last && preg_match('/S-(\d+)/', $last, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return 'S-'.str_pad((string) $nextNumber, 8, '0', STR_PAD_LEFT);
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                // fall through
            }
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($str)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
