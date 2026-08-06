<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsContactTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'Name',
            'ID Number',
            'LRN',
            'Student Mobile Number',
            'Emergency Person to be Contacted',
            'Relationship',
            'Emergency Contact Number',
            'Emergency Person Address',
            'Year',
            'Section',
        ];
    }

    public function array(): array
    {
        return [
            [
                'ALZATE, Alan Jr. TANDOG',
                '1000821942',
                '',
                '09242102712',
                'Joyce Mae S. Tandog',
                'sister',
                '09187103523',
                'Jerome, R.Castillo, Davao City',
                'Grade 7',
                'St. Agnes',
            ],
            [
                'ESCAÑON, Pretty Ericate Mae TORRE',
                '',
                '',
                '09171234567',
                'Cathy Melanie Tarre',
                'mother',
                '09181234567',
                'Davao City',
                'Grade 7',
                'St. Agnes',
            ],
        ];
    }
}
