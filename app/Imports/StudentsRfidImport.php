<?php

namespace App\Imports;

use App\Models\Student;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentsRfidImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $notFound = [];

    /** @var list<string> */
    public array $conflicts = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $rfid = $this->pick($row, 'rfid', 'rfid_code', 'card_number', 'card_no');

            if ($rfid === '') {
                $this->skipped++;

                continue;
            }

            $student = $this->findStudent($row);

            if (! $student) {
                $this->notFound[] = 'Row '.$line.': no student matched ('.$this->identifierLabel($row).')';
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

    /** @return array{updated:int,skipped:int,not_found:list<string>,conflicts:list<string>} */
    public function report(): array
    {
        return [
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'not_found' => $this->notFound,
            'conflicts' => $this->conflicts,
        ];
    }

    private function findStudent(Collection|array $row): ?Student
    {
        $studentId = $this->pick($row, 'idnum', 'id_num', 'student_id', 'id_number');
        if ($studentId !== '') {
            $student = Student::where('student_id', $studentId)->first();
            if ($student) {
                return $student;
            }
        }

        $recordId = $this->pick($row, 'recordid', 'record_id');
        if ($recordId !== '') {
            $student = Student::where('record_id', $recordId)->first();
            if ($student) {
                return $student;
            }
        }

        $qrcode = $this->pick($row, 'qrcode', 'qr_code');
        if ($qrcode !== '') {
            return Student::where('qrcode', $qrcode)->first();
        }

        return null;
    }

    private function identifierLabel(Collection|array $row): string
    {
        foreach (['idnum', 'id_num', 'student_id', 'recordid', 'record_id', 'qrcode'] as $key) {
            $value = $this->pick($row, $key);
            if ($value !== '') {
                return $key.'='.$value;
            }
        }

        return 'no identifier';
    }

    private function pick(Collection|array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = $row instanceof Collection ? $row->get($key) : ($row[$key] ?? null);

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
