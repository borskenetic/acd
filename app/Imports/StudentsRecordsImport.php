<?php

namespace App\Imports;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\Student;
use App\Models\User;
use App\Support\AdvisoryScope;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Update-only import for ACD data records CSV/XLSX.
 *
 * Updates (when present / non-empty):
 *  - RecordID → students.record_id
 *  - CourseStrand → students.course
 *  - GuardianName → students.emergency_person
 *  - GuardianAddress → students.emergency_address
 *  - GuardianContact → students.emergency_number
 *
 * When record_id is newly set or changed, profile_picture is set to
 * images/profile_pictures/{RecordID}.jpg
 *
 * Other spreadsheet columns are ignored. Does not create students.
 */
class StudentsRecordsImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $updated = 0;

    public int $skipped = 0;

    public int $unchanged = 0;

    public int $photosSynced = 0;

    /** @var list<string> */
    public array $notFound = [];

    /** @var list<string> */
    public array $ambiguous = [];

    /** @var list<string> */
    public array $conflicts = [];

    /** @var list<string> */
    public array $noData = [];

    /** @var list<string> */
    public array $outOfScope = [];

    public function __construct(public ?User $actor = null)
    {
        $this->actor = $actor ?? auth()->user();
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $fields = $this->extractFields($row);
            $payload = $this->buildPayload($fields);

            if ($payload === [] && ($fields['record_id'] ?? '') === '') {
                if ($this->hasIdentifier($fields)) {
                    $this->noData[] = 'Row '.$line.': nothing to update ('.$this->identifierLabel($fields).')';
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
                $this->notFound[] = 'Row '.$line.': no student matched ('.$this->identifierLabel($fields).')';
                $this->skipped++;

                continue;
            }

            if ($match['status'] === 'ambiguous') {
                $this->ambiguous[] = 'Row '.$line.': multiple students matched ('.$this->identifierLabel($fields).') — '.$match['detail'];
                $this->skipped++;

                continue;
            }

            /** @var Student $student */
            $student = $match['student'];

            if (! AdvisoryScope::canMutateStudent($student, $this->actor)) {
                $this->outOfScope[] = 'Row '.$line.': outside your grade access ('.$student->year.' · '.$student->section.')';
                $this->skipped++;

                continue;
            }

            $newRecordId = $fields['record_id'] ?? '';
            if ($newRecordId !== '') {
                $taken = Student::query()
                    ->where('record_id', $newRecordId)
                    ->where('id', '!=', $student->id)
                    ->exists();

                if ($taken) {
                    $this->conflicts[] = 'Row '.$line.': RecordID '.$newRecordId.' already assigned to another student';
                    $this->skipped++;

                    continue;
                }
            }

            $changes = [];
            foreach ($payload as $column => $value) {
                $current = trim((string) ($student->{$column} ?? ''));
                if ($current !== $value) {
                    $changes[$column] = $value;
                }
            }

            $recordIdChanged = false;
            if ($newRecordId !== '') {
                $currentRecordId = trim((string) ($student->record_id ?? ''));
                if ($currentRecordId !== $newRecordId) {
                    $changes['record_id'] = $newRecordId;
                    $recordIdChanged = true;
                }
            }

            if ($recordIdChanged) {
                $photoPath = $this->profilePicturePath($newRecordId);
                $currentPhoto = trim((string) ($student->profile_picture ?? ''));
                if ($currentPhoto !== $photoPath) {
                    $changes['profile_picture'] = $photoPath;
                    $this->photosSynced++;
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

    /** @return array{updated:int,skipped:int,unchanged:int,photos_synced:int,not_found:list<string>,ambiguous:list<string>,conflicts:list<string>,no_data:list<string>} */
    public function report(): array
    {
        return [
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'unchanged' => $this->unchanged,
            'photos_synced' => $this->photosSynced,
            'not_found' => $this->notFound,
            'ambiguous' => $this->ambiguous,
            'conflicts' => $this->conflicts,
            'no_data' => $this->noData,
            'out_of_scope' => $this->outOfScope,
        ];
    }

    private function profilePicturePath(string $recordId): string
    {
        // App convention: relative path under project root (asset() / base_path()).
        return 'images/profile_pictures/'.$recordId.'.jpg';
    }

    /**
     * @return array<string,string>
     */
    private function extractFields(Collection|array $row): array
    {
        return [
            'record_id' => $this->pick($row, 'recordid', 'record_id', 'record id'),
            'student_id' => $this->pick($row, 'idnum', 'id_num', 'student_id', 'id_number', 'id number'),
            'lrn' => $this->pickLrn($row),
            'lastname' => $this->pick($row, 'lastname', 'last_name', 'last name'),
            'firstname' => $this->pick($row, 'firstname', 'first_name', 'first name'),
            'course' => $this->pick($row, 'coursestrand', 'course_strand', 'course strand', 'course'),
            'emergency_person' => $this->cleanText($this->pick($row, 'guardianname', 'guardian_name', 'guardian name', 'emergency_person')),
            'emergency_address' => $this->cleanText($this->pick($row, 'guardianaddress', 'guardian_address', 'guardian address', 'emergency_address')),
            'emergency_number' => $this->normalizePhone($this->pick($row, 'guardiancontact', 'guardian_contact', 'guardian contact', 'emergency_number')),
        ];
    }

    /**
     * Fields other than record_id (handled separately for photo sync).
     *
     * @param  array<string,string>  $fields
     * @return array<string,string>
     */
    private function buildPayload(array $fields): array
    {
        $payload = [];
        foreach (['course', 'emergency_person', 'emergency_address', 'emergency_number'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                $payload[$key] = $fields[$key];
            }
        }

        return $payload;
    }

    /** @param  array<string,string>  $fields */
    private function hasIdentifier(array $fields): bool
    {
        return ($fields['student_id'] ?? '') !== ''
            || ($fields['lrn'] ?? '') !== ''
            || ($fields['record_id'] ?? '') !== ''
            || (($fields['lastname'] ?? '') !== '' && ($fields['firstname'] ?? '') !== '');
    }

    /** @param  array<string,string>  $fields */
    private function identifierLabel(array $fields): string
    {
        foreach (['student_id', 'lrn', 'record_id'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                return $key.'='.$fields[$key];
            }
        }

        $last = $fields['lastname'] ?? '';
        $first = $fields['firstname'] ?? '';
        if ($last !== '' || $first !== '') {
            return 'name='.$last.', '.$first;
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

        // Match existing record if already assigned (re-run / partial sync)
        $recordId = $fields['record_id'] ?? '';
        if ($recordId !== '') {
            $student = Student::where('record_id', $recordId)->first();
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
        $lastname = trim($fields['lastname'] ?? '');
        $firstname = trim($fields['firstname'] ?? '');
        if ($lastname === '' || $firstname === '') {
            return ['status' => 'not_found'];
        }

        $query = Student::query()
            ->whereRaw('LOWER(lastname) = ?', [mb_strtolower($lastname)])
            ->where(function ($q) use ($firstname) {
                $first = mb_strtolower($firstname);
                $q->whereRaw('LOWER(firstname) = ?', [$first])
                    ->orWhereRaw('LOWER(firstname) LIKE ?', [$first.' %']);
            });

        $matches = $query->limit(5)->get();

        if ($matches->count() === 1) {
            return ['status' => 'ok', 'student' => $matches->first()];
        }

        if ($matches->isEmpty()) {
            $normalized = NormalizeStudentNames::normalizeFullName($firstname.' '.$lastname);
            if ($normalized !== '' && $normalized !== null) {
                $matches = Student::query()
                    ->where('normalized_name', $normalized)
                    ->limit(5)
                    ->get();
                if ($matches->count() === 1) {
                    return ['status' => 'ok', 'student' => $matches->first()];
                }
            }
        }

        if ($matches->isEmpty()) {
            return ['status' => 'not_found'];
        }

        $detail = $matches->map(fn (Student $s) => '#'.$s->id.' '.$s->lastname.', '.$s->firstname)->implode('; ');

        return ['status' => 'ambiguous', 'detail' => $detail];
    }

    private function cleanText(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '' || preg_match('/^(n\/?a|none|null|-+|\.+)$/i', $value)) {
            return '';
        }

        return $value;
    }

    private function normalizePhone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^(n\/?a|none|null|-+|\.+)$/i', $raw)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            return $digits;
        }

        // International / other lengths — keep digits as-is
        return $digits;
    }

    private function pickLrn(Collection|array $row): string
    {
        $raw = $this->pick($row, 'lrn', 'learner_reference_number', 'learners_reference_number');
        if ($raw === '') {
            return '';
        }

        if (is_numeric($raw)) {
            $digits = preg_replace('/\D/', '', sprintf('%.0f', (float) $raw)) ?? '';

            return $digits;
        }

        return preg_replace('/\s+/', '', $raw) ?? '';
    }

    private function pick(Collection|array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = $row instanceof Collection ? $row->get($key) : ($row[$key] ?? null);

            if ($value === null && $row instanceof Collection) {
                $value = $row->get(str_replace(' ', '_', $key));
            }

            if ($value === null) {
                continue;
            }

            if (is_float($value) || is_int($value)) {
                $value = sprintf('%.0f', $value);
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
