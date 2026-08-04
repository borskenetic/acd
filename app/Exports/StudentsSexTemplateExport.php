<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsSexTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['Name', 'Gender', 'ID Number', 'RFID', 'LRN', 'Year', 'Section'];
    }

    public function array(): array
    {
        return [
            ['DELA CRUZ, Juan SANTOS', 'Male', '1000820001', '', '', 'Grade 7', 'St. Agnes'],
            ['REYES, Maria ANNE', 'Female', '1000820002', '', '', 'Grade 7', 'St. Agnes'],
        ];
    }
}
