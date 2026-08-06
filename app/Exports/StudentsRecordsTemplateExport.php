<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsRecordsTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'RecordID',
            'IDNum',
            'LastName',
            'FirstName',
            'CourseStrand',
            'GuardianName',
            'GuardianAddress',
            'GuardianContact',
            'LRN',
        ];
    }

    public function array(): array
    {
        return [
            [
                '12203',
                '1000822483',
                'TIGNAWAN',
                'MIL GRACE',
                'TECHPRO-ICT',
                'RHYZIL LIENA OTORDOS',
                'Agdao Davao City',
                '09755524229',
                '',
            ],
        ];
    }
}
