<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'Name',
            'ID Number',
            'LRN',
            'RFID',
            'Year',
            'Section',
        ];
    }

    public function array(): array
    {
        return [
            [
                'DELA CRUZ, Juan SANTOS',
                '1000822390',
                '123456789012',
                '570927308707641',
                'Grade 5',
                'Mabini',
            ],
            [
                'LIMBING, Abby',
                '1000822391',
                '',
                '482613207342004',
                'Kinder',
                'Rose',
            ],
        ];
    }
}
