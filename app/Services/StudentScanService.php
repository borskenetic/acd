<?php

namespace App\Services;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\AttendanceLog;
use App\Models\GateDevice;
use App\Models\Setting;
use App\Models\Student;
use Carbon\Carbon;

class StudentScanService
{
    public function __construct(
        protected AttendanceSessionService $sessions,
        protected StudentDeparturePolicy $departure,
        protected AttendanceSmsService $sms,
        protected StudentSessionScheduleService $sessionSchedule,
    ) {}

    public function resolveStudent(string $raw): ?Student
    {
        $token = trim(str_replace("\r", '', $raw));
        $student = Student::where('qrcode', $token)->first();

        if (! $student && $token !== '') {
            $student = Student::where('rfid', $token)->first();
        }

        $parsed = $this->parseQr($raw);

        if (! $student && $parsed['student_no']) {
            $student = Student::where('student_id', $parsed['student_no'])->first();
        }

        if (! $student && $parsed['full_name']) {
            $qrName = NormalizeStudentNames::normalizeFullName($parsed['full_name']);
            $student = Student::where('normalized_name', $qrName)->first();
        }

        return $student;
    }

    /** @return array{student_no: ?string, full_name: ?string, course: ?string} */
    public function parseQr(string $raw): array
    {
        $raw = trim(str_replace("\r", '', $raw));

        if (str_contains($raw, "\n")) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));

            return [
                'student_no' => $lines[0] ?? null,
                'full_name' => $lines[1] ?? null,
                'course' => $lines[2] ?? null,
            ];
        }

        $parts = array_map('trim', explode(',', $raw));

        if (preg_match('/^\d{2}-\d+$/', $parts[0] ?? '')) {
            return [
                'student_no' => $parts[0] ?? null,
                'full_name' => $parts[1] ?? null,
                'course' => $parts[2] ?? null,
            ];
        }

        return [
            'student_no' => null,
            'full_name' => $parts[0] ?? null,
            'course' => $parts[1] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function previewScan(Student $student): array
    {
        $this->sessions->closeStaleOpenInForStudent($student);

        if ($this->sessionSchedule->usesSessionModel($student)) {
            $decision = $this->sessionSchedule->decideNextScan($student);

            if ($decision['type'] === 'already_scanned') {
                return [
                    'type' => 'already_scanned',
                    'message' => $decision['message'],
                    'session_label' => $decision['session_label'] ?? 'today',
                    'last_status' => $decision['last_status'] ?? null,
                    'student' => $this->studentPayload($student, detailed: true),
                ];
            }

            if ($decision['type'] === 'early_out_blocked') {
                return [
                    'type' => 'early_out_blocked',
                    'message' => $decision['message'],
                    'allowed_after' => $decision['allowed_after'],
                    'student' => $this->studentPayload($student, detailed: true),
                ];
            }

            return [
                'type' => 'student',
                'next_status' => $decision['next_status'],
                'session_key' => $decision['session_key'] ?? null,
                'session_label' => $decision['session_label'] ?? null,
                'student_id' => $student->id,
                'logout_feedback_enabled' => $this->logoutFeedbackEnabled(),
                'section_picker_enabled' => $this->sectionPickerEnabled(),
                'student' => $this->studentPayload($student),
            ];
        }

        // SHS / College (and unmatched years): still enforce anti-rescan cooldown.
        $lastLog = $this->lastLogForStudent($student);
        $cooldown = $this->sessionSchedule->cooldownBlockIfNeeded($student, $lastLog);
        if ($cooldown !== null) {
            return [
                'type' => 'already_scanned',
                'message' => $cooldown['message'],
                'session_label' => $cooldown['session_label'] ?? 'current',
                'last_status' => $cooldown['last_status'] ?? null,
                'student' => $this->studentPayload($student, detailed: true),
            ];
        }

        $nextStatus = ($lastLog && $this->sessions->isInStatus($lastLog->status)) ? 'OUT' : 'IN';

        if ($nextStatus === 'OUT' && $this->departure->blocksCheckout($student)) {
            return [
                'type' => 'early_out_blocked',
                'message' => $this->earlyOutMessage(),
                'allowed_after' => $this->departure->earliestOutLabel(),
                'student' => $this->studentPayload($student, detailed: true),
            ];
        }

        return [
            'type' => 'student',
            'next_status' => $nextStatus,
            'student_id' => $student->id,
            'logout_feedback_enabled' => $this->logoutFeedbackEnabled(),
            'section_picker_enabled' => $this->sectionPickerEnabled(),
            'student' => $this->studentPayload($student),
        ];
    }

    /**
     * @return array{status: string, scanned_at: string, log: AttendanceLog, session_key?: ?string}
     */
    public function recordScan(
        Student $student,
        ?string $section = null,
        ?Carbon $scannedAt = null,
        ?string $clientUuid = null,
        ?GateDevice $gateDevice = null,
        string $source = 'web',
        bool $sendSms = true,
        ?string $forcedStatus = null,
        ?string $sessionKey = null,
    ): array {
        $this->sessions->closeStaleOpenInForStudent($student);

        $scannedAt ??= now($this->sessionSchedule->timezone());
        $sessionKeyResolved = $sessionKey;

        if ($forcedStatus !== null) {
            $newStatus = strtoupper($forcedStatus);
        } elseif ($this->sessionSchedule->usesSessionModel($student)) {
            $decision = $this->sessionSchedule->decideNextScan($student, $scannedAt);

            if ($decision['type'] === 'already_scanned') {
                throw new \RuntimeException($decision['message'] ?? 'Already scanned.');
            }

            if ($decision['type'] === 'early_out_blocked') {
                throw new \RuntimeException($decision['message'] ?? $this->earlyOutMessage());
            }

            $newStatus = $decision['next_status'];
            $sessionKeyResolved = $decision['session_key'] ?? null;
        } else {
            $lastLog = $this->lastLogForStudent($student);
            $cooldown = $this->sessionSchedule->cooldownBlockIfNeeded($student, $lastLog, $scannedAt);
            if ($cooldown !== null) {
                throw new \RuntimeException($cooldown['message'] ?? 'Already scanned.');
            }

            $newStatus = ($lastLog && $this->sessions->isInStatus($lastLog->status)) ? 'OUT' : 'IN';

            if ($newStatus === 'OUT' && $this->departure->blocksCheckout($student, $scannedAt)) {
                throw new \RuntimeException($this->earlyOutMessage());
            }
        }

        if ($section !== null && $section !== '') {
            $allowed = Setting::attendanceSections();
            if (! in_array($section, $allowed, true)) {
                throw new \InvalidArgumentException('Invalid section selected.');
            }
        } else {
            $section = null;
        }

        $log = AttendanceLog::create([
            'student_id' => $student->id,
            'section' => $section,
            'status' => $newStatus,
            'scanned_at' => $scannedAt,
            'client_uuid' => $clientUuid,
            'gate_device_id' => $gateDevice?->id,
            'source' => $source,
        ]);

        if ($sendSms) {
            $this->sms->handleStudentScan($student, $newStatus, $log->scanned_at, $sessionKeyResolved);
        }

        return [
            'status' => $newStatus,
            'scanned_at' => $log->scanned_at->format('Y-m-d h:i:s A'),
            'log' => $log,
            'session_key' => $sessionKeyResolved,
        ];
    }

    /** Record a scan with status already decided offline (gate sync upload). */
    public function recordSyncedScan(
        Student $student,
        string $status,
        Carbon $scannedAt,
        ?string $section,
        string $clientUuid,
        GateDevice $gateDevice,
    ): AttendanceLog {
        $existing = AttendanceLog::where('client_uuid', $clientUuid)->first();
        if ($existing) {
            return $existing;
        }

        if ($section !== null && $section !== '') {
            $allowed = Setting::attendanceSections();
            if (! in_array($section, $allowed, true)) {
                $section = null;
            }
        } else {
            $section = null;
        }

        $status = strtoupper(trim($status));
        if (! in_array($status, ['IN', 'OUT'], true)) {
            throw new \InvalidArgumentException('Invalid scan status.');
        }

        $sessionKey = null;
        if ($this->sessionSchedule->usesSessionModel($student)) {
            $halfDay = $this->sessionSchedule->isHalfDayToday($student, $scannedAt);
            $count = $this->sessionSchedule->todayLogs($student, $scannedAt)->count();
            $expected = $this->sessionSchedule->expectedAction($count, $halfDay);
            $sessionKey = $expected['session_key'] ?? null;
        }

        $log = AttendanceLog::create([
            'student_id' => $student->id,
            'section' => $section,
            'status' => $status,
            'scanned_at' => $scannedAt,
            'client_uuid' => $clientUuid,
            'gate_device_id' => $gateDevice->id,
            'source' => 'gate_sync',
        ]);

        $this->sms->handleStudentScan($student, $status, $log->scanned_at, $sessionKey);

        return $log;
    }

    /**
     * Create an automatic attendance row (lunch autofill / EOD) and optionally SMS.
     */
    public function recordAutomaticScan(
        Student $student,
        string $status,
        Carbon $scannedAt,
        string $source,
        ?string $sessionKey = null,
        bool $sendSms = true,
        ?string $smsEvent = null,
    ): AttendanceLog {
        $log = AttendanceLog::create([
            'student_id' => $student->id,
            'section' => null,
            'status' => strtoupper($status),
            'scanned_at' => $scannedAt,
            'source' => $source,
        ]);

        if ($sendSms) {
            $this->sms->handleStudentScan(
                $student,
                strtoupper($status),
                $log->scanned_at,
                $sessionKey,
                $smsEvent
            );
        }

        return $log;
    }

    /** @return array<string, mixed> */
    public function studentPayload(Student $student, bool $detailed = false): array
    {
        $payload = [
            'id' => $student->id,
            'record_id' => $student->record_id,
            'student_id' => $student->student_id,
            'qrcode' => $student->qrcode,
            'rfid' => $student->rfid,
            'firstname' => $student->firstname,
            'lastname' => $student->lastname,
            'middle_initial' => $student->middle_initial,
            'profile_picture' => $student->profile_picture,
            'normalized_name' => $student->normalized_name,
            'educational_level' => $student->educational_level?->value ?? $student->educational_level,
            'year' => $student->year,
        ];

        if ($detailed) {
            $payload['educational_level_label'] = $student->educational_level?->label()
                ?? $student->educational_level;
        }

        return $payload;
    }

    public function lastLogForStudent(Student $student): ?AttendanceLog
    {
        return AttendanceLog::where('student_id', $student->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();
    }

    public function logoutFeedbackEnabled(): bool
    {
        if (! config('attendance.logout_feedback_enabled')) {
            return false;
        }

        return Setting::logoutFeedbackEnabled();
    }

    public function sectionPickerEnabled(): bool
    {
        if (! config('attendance.section_picker_enabled')) {
            return false;
        }

        return Setting::sectionPickerEnabled();
    }

    public function earlyOutMessage(): string
    {
        return str_replace(
            '{time}',
            $this->departure->earliestOutLabel(),
            $this->departure->blockMessage()
        );
    }
}
