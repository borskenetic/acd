<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsRfidTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'IDNum',
            'RFID',
        ];
    }

    public function array(): array
    {
        return [
            ['2024-00001', '1234567890'],
        ];
    }
}
