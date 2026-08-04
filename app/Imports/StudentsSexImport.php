<?php

namespace App\Imports;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\Student;
use App\Support\StudentNameParser;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Update-only import: sets students.sex from CSV/XLSX.
 * Does not create students. Prefer ID Number; fall back to LRN, RFID, then name.
 */
class StudentsSexImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $updated = 0;

    public int $skipped = 0;

    public int $unchanged = 0;

    /** @var list<string> */
    public array $notFound = [];

    /** @var list<string> */
    public array $ambiguous = [];

    /** @var list<string> */
    public array $invalid = [];

    public function __construct(public bool $dryRun = false) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $line = $index + 2;

            $sex = $this->normalizeSex(
                $this->pick($row, 'gender', 'sex', 'male_female')
            );

            if ($sex === null) {
                $raw = $this->pick($row, 'gender', 'sex', 'male_female');
                if ($raw === '') {
                    $this->skipped++;

                    continue;
                }

                $this->invalid[] = 'Row '.$line.': invalid gender "'.$raw.'" (use Male/Female or male/female)';
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

            if (strtolower((string) $student->sex) === $sex) {
                $this->unchanged++;

                continue;
            }

            if (! $this->dryRun) {
                $student->update(['sex' => $sex]);
            }

            $this->updated++;
        }
    }

    /** @return array{updated:int,skipped:int,unchanged:int,not_found:list<string>,ambiguous:list<string>,invalid:list<string>,dry_run:bool} */
    public function report(): array
    {
        return [
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'unchanged' => $this->unchanged,
            'not_found' => $this->notFound,
            'ambiguous' => $this->ambiguous,
            'invalid' => $this->invalid,
            'dry_run' => $this->dryRun,
        ];
    }

    /**
     * @return array{status:string,student?:Student,detail?:string}
     */
    private function findStudent(Collection|array $row): array
    {
        $studentId = $this->pick($row, 'idnum', 'id_num', 'student_id', 'id_number', 'id number');
        if ($studentId !== '') {
            // Some school IDs were imported with suffixes like "1000821392-1"
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

        $lrn = $this->pick($row, 'lrn');
        if ($lrn !== '') {
            $student = Student::where('lrn', $lrn)->first();
            if ($student) {
                return ['status' => 'ok', 'student' => $student];
            }
        }

        $rfid = $this->pick($row, 'rfid', 'rfid_code');
        if ($rfid !== '') {
            // Strip leading zeros for looser match if exact fails
            $student = Student::where('rfid', $rfid)->first()
                ?? Student::where('rfid', ltrim($rfid, '0'))->first();
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

    private function normalizeSex(string $raw): ?string
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return null;
        }

        return match ($v) {
            'male', 'm', 'boy', 'man' => 'male',
            'female', 'f', 'girl', 'woman' => 'female',
            default => null,
        };
    }

    private function identifierLabel(Collection|array $row): string
    {
        foreach (['idnum', 'id_num', 'student_id', 'id_number', 'id number', 'lrn', 'rfid', 'name'] as $key) {
            $value = $this->pick($row, $key);
            if ($value !== '') {
                return $key.'='.$value;
            }
        }

        return 'no identifier';
    }

    private function pick(Collection|array $row, string ...$keys): string
    {
        // HeadingRow slugifies: "ID Number" -> "id_number", "Gender" -> "gender"
        foreach ($keys as $key) {
            $value = $row instanceof Collection ? $row->get($key) : ($row[$key] ?? null);

            if ($value === null && $row instanceof Collection) {
                // Try common slug variants (spaces vs underscores)
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
