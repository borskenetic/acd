<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Attendance Logs PDF</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #444; padding: 6px; text-align: left; }
        th { background-color: #f0f0f0; }
    </style>
</head>
<body>
    <h2>Attendance Logs</h2>
    <table>
        <thead>
            <tr>
                <th>Last Name</th>
                <th>First Name</th>
                <th>Course</th> 
                <th>Section</th>
                <th>Kiosk</th>
                <th>Status</th>
                <th>Scanned At</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
                <tr>
                    <td>{{ $log->student->lastname ?? 'Unknown' }}</td>
                    <td>{{ $log->student->firstname ?? 'Unknown' }}</td>
                    <td>{{ $log->student->course ?? 'Unknown' }}</td>
                    <td>{{ $log->section ?? '—' }}</td>
                    <td>{{ $log->kioskLabel() }}</td>
                    <td>{{ strtoupper($log->status) }}</td>
                    <td>
                        {{ $log->scanned_at?->format('Y-m-d h:i A') ?? '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
