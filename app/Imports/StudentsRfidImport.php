<?php

namespace App\Imports;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\Student;
use App\Models\User;
use App\Support\AdvisoryScope;
use App\Support\StudentNameParser;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Update-only import: sets students.rfid from CSV/XLSX.
 * Prefer ID Number; fall back to LRN, RecordID, QR, then Name (+ Year/Section).
 */
class StudentsRfidImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $notFound = [];

    /** @var list<string> */
    public array $ambiguous = [];

    /** @var list<string> */
    public array $conflicts = [];

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
            $rfid = $this->pick($row, 'rfid', 'rfid_code', 'card_number', 'card_no');

            if ($rfid === '') {
                $this->skipped++;

                continue;
            }

            $match = $this->findStudent($row);

            if ($match['status'] === 'not_found') {
                $this->notFound[] = 'Row '.$line.': no student matched ('.$this->identifierLabel($row).')';
                $this->skipped++;

                continue;
            }

            if ($match['status'] === 'ambiguous') {
                $this->ambiguous[] = 'Row '.$line.': multiple students matched ('.$this->identifierLabel($row).') — '.$match['detail'];
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

            $conflict = Student::query()
                ->where('rfid', $rfid)
                ->where('id', '!=', $student->id)
                ->exists();

            if ($conflict) {
                $this->conflicts[] = 'Row '.$line.': RFID '.$rfid.' is already assigned to another student';
                $this->skipped++;

                continue;
            }

            $student->update(['rfid' => $rfid]);
            $this->updated++;
        }
    }

    /** @return array{updated:int,skipped:int,not_found:list<string>,ambiguous:list<string>,conflicts:list<string>,out_of_scope:list<string>} */
    public function report(): array
    {
        return [
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'not_found' => $this->notFound,
            'ambiguous' => $this->ambiguous,
            'conflicts' => $this->conflicts,
            'out_of_scope' => $this->outOfScope,
        ];
    }

    /**
     * @return array{status:string,student?:Student,detail?:string}
     */
    private function findStudent(Collection|array $row): array
    {
        $studentId = $this->pick($row, 'idnum', 'id_num', 'student_id', 'id_number', 'id number');
        if ($studentId !== '') {
            $student = Student::where('student_id', $studentId)->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
            // Some school IDs were imported with suffixes like "1000821392-1"
            $baseId = preg_replace('/-\d+$/', '', $studentId) ?? $studentId;
            if ($baseId !== $studentId) {
                $student = Student::where('student_id', $baseId)->first();
                if ($student) {
                    return ['status' => 'ok', 'student' => $student];
                }
            }
        }

        $lrn = $this->pick($row, 'lrn');
        if ($lrn !== '') {
            $student = Student::where('lrn', $lrn)->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
        }

        $recordId = $this->pick($row, 'recordid', 'record_id');
        if ($recordId !== '') {
            $student = Student::where('record_id', $recordId)->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
        }

        $qrcode = $this->pick($row, 'qrcode', 'qr_code');
        if ($qrcode !== '') {
            $student = Student::where('qrcode', $qrcode)->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
        }

        return $this->findByName($row);
    }

    /**
     * @return array{status:string,student?:Student,detail?:string}
     */
    private function findByName(Collection|array $row): array
    {
        $name = $this->pick($row, 'name', 'student_name', 'fullname');
        if ($name === '') {
            return ['status' => 'not_found'];
        }

        $parsed = StudentNameParser::parse($name);
        $year = $this->pick($row, 'year', 'grade', 'level');
        $section = $this->pick($row, 'section');

        $query = Student::query();

        if ($parsed) {
            $query->whereRaw('LOWER(lastname) = ?', [mb_strtolower($parsed['lastname'])])
                ->where(function ($q) use ($parsed) {
                    $first = mb_strtolower($parsed['firstname']);
                    $q->whereRaw('LOWER(firstname) = ?', [$first])
                        ->orWhereRaw('LOWER(firstname) LIKE ?', [$first.' %']);
                });
        } else {
            // Free-form name: match normalized firstname+lastname
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

        // Retry without year/section if they over-constrained
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

        if ($matches->isEmpty()) {
            return ['status' => 'not_found'];
        }

        $detail = $matches->map(fn (Student $s) => '#'.$s->id.' '.$s->lastname.', '.$s->firstname)->implode('; ');

        return ['status' => 'ambiguous', 'detail' => $detail];
    }

    private function identifierLabel(Collection|array $row): string
    {
        foreach (['idnum', 'id_num', 'student_id', 'id_number', 'id number', 'lrn', 'recordid', 'record_id', 'qrcode', 'name'] as $key) {
            $value = $this->pick($row, $key);
            if ($value !== '') {
                return $key.'='.$value;
            }
        }

        return 'no identifier';
    }

    private function pick(Collection|array $row, string ...$keys): string
    {
        // HeadingRow slugifies: "ID Number" -> "id_number", "Name" -> "name"
        foreach ($keys as $key) {
            $value = $row instanceof Collection ? $row->get($key) : ($row[$key] ?? null);

            if ($value === null && $row instanceof Collection) {
                $value = $row->get(str_replace(' ', '_', $key));
            }

            if ($value === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
