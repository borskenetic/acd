<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MissedOutsExport implements FromCollection, WithHeadings
{
    public function __construct(protected Collection $logs) {}

    public function collection()
    {
        $tz = config('app.timezone', 'Asia/Manila');

        return $this->logs->map(function ($log) use ($tz) {
            $student = $log->student;
            $outAt = $log->scanned_at?->timezone($tz);
            $inAt = $log->last_in_at?->timezone($tz);

            return [
                'student_id' => $student->student_id ?? '',
                'lastname' => $student->lastname ?? 'Unknown',
                'firstname' => $student->firstname ?? 'Unknown',
                'year' => $student->year ?? '',
                'section' => $student->section ?? '',
                'course' => $student->course ?? '',
                'last_in' => $inAt?->format('Y-m-d h:i A') ?? '—',
                'auto_out' => $outAt?->format('Y-m-d h:i A') ?? '—',
                'date' => $outAt?->format('Y-m-d') ?? '—',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Student ID',
            'Last Name',
            'First Name',
            'Grade',
            'Section',
            'Course',
            'Last IN',
            'Auto OUT (Missed)',
            'Date',
        ];
    }
}
