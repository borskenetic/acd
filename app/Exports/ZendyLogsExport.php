<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ZendyLogsExport implements FromCollection, WithHeadings
{
    public function __construct(private Collection $logs) {}

    public function collection()
    {
        return $this->logs->map(function ($log) {
            $actorName = trim(implode(' ', array_filter([
                optional($log->actor)->fname,
                optional($log->actor)->lname,
            ])));

            return [
                'id' => $log->id,
                'actor' => $actorName !== '' ? $actorName : ($log->email ?? '—'),
                'role' => $log->actor_role ?? optional($log->actor)->role ?? '—',
                'action' => $log->actionLabel(),
                'name' => trim(($log->first_name ?? '').' '.($log->last_name ?? '')) ?: '—',
                'email' => $log->email ?? '—',
                'course' => $log->course ?? '—',
                'department' => $log->department ?? '—',
                'campus' => $log->campus ?? '—',
                'duration' => $log->durationLabel() ?? '—',
                'time' => $log->created_at?->format('Y-m-d H:i') ?? '—',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Actor',
            'Role',
            'Action',
            'Name',
            'Email',
            'Course',
            'Department',
            'Campus',
            'Duration',
            'Time',
        ];
    }
}
