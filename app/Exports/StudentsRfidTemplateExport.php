<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsRfidTemplateExport implements FromArray, WithHeadings
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
            // Prefer ID Number when available
            ['DOE, Jane A', '2024-00001', '', '1234567890', '', ''],
            // Name works when ID Number is blank (add Year/Section if names collide)
            ['SMITH, John B', '', '', '0987654321', 'Grade 1', 'A'],
        ];
    }
}
