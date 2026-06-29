<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsImportTemplateExport implements FromArray, WithHeadings
{
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

    public function array(): array
    {
        return [
            [
                '1001',
                '2024-00001',
                'Dela Cruz',
                'Juan',
                'Santos',
                '2008-03-15',
                'Grade 10',
                'STEM',
                'Maria Dela Cruz',
                '123 Main St, Davao City',
                '09171234567',
                '',
            ],
        ];
    }
}
