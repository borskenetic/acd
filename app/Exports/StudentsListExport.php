<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsListExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected Collection $students
    ) {}

    public function collection()
    {
        return $this->students->map(fn ($s) => [
            $s->record_id ?? '',
            $s->student_id ?? '',
            $s->lastname,
            $s->firstname,
            $s->middle_initial ?? '',
            $s->birth_date ?? '',
            $s->year ?? '',
            $s->course ?? '',
            $s->emergency_person ?? '',
            $s->emergency_address ?? '',
            $s->emergency_number ?? '',
            $s->rfid ?? '',
        ]);
    }

    public function headings(): array
    {
        return [
            'RecordID',
            'IDNum',
            'LastName',
            'FirstName',
            'MiddleName',
            'Birthday',
            'GradeLevel',
            'CourseStrand',
            'GuardianName',
            'GuardianAddress',
            'GuardianContact',
            'RFID',
        ];
    }
}
