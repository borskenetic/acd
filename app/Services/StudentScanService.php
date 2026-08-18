<?php

namespace App\Services;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\AttendanceLog;
use App\Models\GateDevice;
use App\Models\Setting;
use App\Models\Student;
use App\Support\ScanConfirmToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
                'confirm_token' => ScanConfirmToken::issue('student', (int) $student->id),
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
            'confirm_token' => ScanConfirmToken::issue('student', (int) $student->id),
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
        return DB::transaction(function () use (
            $student,
            $section,
            $scannedAt,
            $clientUuid,
            $gateDevice,
            $source,
            $sendSms,
            $forcedStatus,
            $sessionKey,
        ) {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();

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
                'kiosk_name' => $gateDevice?->name,
                'status' => $newStatus,
                'scanned_at' => $scannedAt,
                'client_uuid' => $clientUuid,
                'gate_device_id' => $gateDevice?->id,
                'source' => $source,
            ]);

            if ($sendSms) {
                $this->sms->handleStudentScan(
                    $student,
                    $newStatus,
                    $log->scanned_at,
                    $sessionKeyResolved,
                    null,
                    $gateDevice,
                );
            }

            return [
                'status' => $newStatus,
                'scanned_at' => $log->scanned_at->format('Y-m-d h:i:s A'),
                'log' => $log,
                'session_key' => $sessionKeyResolved,
            ];
        });
    }

    /**
     * Record a scan uploaded from an offline gate terminal.
     *
     * Kiosk-supplied IN/OUT is ignored: the server derives status from this student's
     * existing history so a stale kiosk cannot create a second IN at another gate.
     * After insert, same-day rows are re-sequenced for out-of-order uploads.
     */
    public function recordSyncedScan(
        Student $student,
        string $status,
        Carbon $scannedAt,
        ?string $section,
        string $clientUuid,
        GateDevice $gateDevice,
    ): AttendanceLog {
        return DB::transaction(function () use ($student, $status, $scannedAt, $section, $clientUuid, $gateDevice) {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();

            $existing = AttendanceLog::where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $existing;
            }

            // Accept either case from devices; status is recomputed below (not used raw).
            $status = strtoupper(trim($status));
            if (! in_array($status, ['IN', 'OUT'], true)) {
                throw new \InvalidArgumentException('Invalid scan status.');
            }

            if ($section !== null && $section !== '') {
                $allowed = Setting::attendanceSections();
                if (! in_array($section, $allowed, true)) {
                    $section = null;
                }
            } else {
                $section = null;
            }

            $this->sessions->closeStaleOpenInForStudent($student);

            $scannedAt = $scannedAt->copy()->timezone($this->sessionSchedule->timezone());
            $resolved = $this->resolveStatusFromHistory($student, $scannedAt);
            $status = $resolved['status'];
            $sessionKey = $resolved['session_key'];

            $log = AttendanceLog::create([
                'student_id' => $student->id,
                'section' => $section,
                'kiosk_name' => $gateDevice->name,
                'status' => $status,
                'scanned_at' => $scannedAt,
                'client_uuid' => $clientUuid,
                'gate_device_id' => $gateDevice->id,
                'source' => 'gate_sync',
            ]);

            $this->reconcileStudentDayToggleStatuses($student, $scannedAt);

            $log->refresh();
            $status = strtoupper((string) $log->status);

            $this->sms->handleStudentScan(
                $student,
                $status,
                $log->scanned_at,
                $sessionKey,
                null,
                $gateDevice,
            );

            return $log;
        });
    }

    /**
     * Next IN/OUT from logs strictly before $scannedAt (server history wins).
     *
     * @return array{status: string, session_key: ?string}
     */
    public function resolveStatusFromHistory(Student $student, Carbon $scannedAt): array
    {
        $sessionKey = null;

        if ($this->sessionSchedule->usesSessionModel($student)) {
            $halfDay = $this->sessionSchedule->isHalfDayToday($student, $scannedAt);
            $priorToday = AttendanceLog::query()
                ->where('student_id', $student->id)
                ->whereDate('scanned_at', $scannedAt->toDateString())
                ->where('scanned_at', '<', $scannedAt)
                ->count();
            $expected = $this->sessionSchedule->expectedAction($priorToday, $halfDay);
            if ($expected !== null) {
                return [
                    'status' => $expected['status'],
                    'session_key' => $expected['session_key'] ?? null,
                ];
            }
            // Past max session scans for the day — still alternate from prior log.
        }

        $prev = $this->lastLogBefore($student, $scannedAt);
        $status = ($prev && $this->sessions->isInStatus($prev->status)) ? 'OUT' : 'IN';

        return [
            'status' => $status,
            'session_key' => $sessionKey,
        ];
    }

    /**
     * Plan IN/OUT fixes for one student on one calendar day (no writes).
     *
     * @return list<array{id: int, student_id: int, from: string, to: string, scanned_at: ?string, kiosk_name: ?string, source: ?string}>
     */
    public function planStudentDayToggleRepairs(Student $student, Carbon $at): array
    {
        $tz = $this->sessionSchedule->timezone();
        $dayStart = $at->copy()->timezone($tz)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();

        // Each calendar day starts expecting IN. Prior-day open INs are handled by
        // close-stale; using them as still-open would flip today's first IN → OUT.
        $open = false;

        $logs = AttendanceLog::query()
            ->where('student_id', $student->id)
            ->whereBetween('scanned_at', [$dayStart, $dayEnd])
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->get();

        $changes = [];
        foreach ($logs as $log) {
            $expected = $open ? 'OUT' : 'IN';
            $actual = strtoupper((string) $log->status);
            if ($actual !== $expected) {
                $changes[] = [
                    'id' => (int) $log->id,
                    'student_id' => (int) $log->student_id,
                    'from' => $actual,
                    'to' => $expected,
                    'scanned_at' => $log->scanned_at?->toDateTimeString(),
                    'kiosk_name' => $log->kiosk_name,
                    'source' => $log->source,
                ];
            }
            $open = $expected === 'IN';
        }

        return $changes;
    }

    /**
     * Re-apply IN/OUT along the calendar day so late-arriving earlier scans stay consistent.
     * Does not re-send SMS for corrected rows.
     *
     * @return int Number of rows updated
     */
    public function reconcileStudentDayToggleStatuses(Student $student, Carbon $at): int
    {
        $changes = $this->planStudentDayToggleRepairs($student, $at);
        if ($changes === []) {
            return 0;
        }

        $byId = collect($changes)->keyBy('id');
        foreach ($byId as $id => $change) {
            AttendanceLog::query()
                ->whereKey($id)
                ->update(['status' => $change['to']]);
        }

        return $byId->count();
    }

    public function lastLogBefore(Student $student, Carbon $scannedAt): ?AttendanceLog
    {
        return AttendanceLog::query()
            ->where('student_id', $student->id)
            ->where('scanned_at', '<', $scannedAt)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Create an automatic attendance row (lunch autofill / EOD). SMS off by default.
     */
    public function recordAutomaticScan(
        Student $student,
        string $status,
        Carbon $scannedAt,
        string $source,
        ?string $sessionKey = null,
        bool $sendSms = false,
        ?string $smsEvent = null,
    ): AttendanceLog {
        return DB::transaction(function () use ($student, $status, $scannedAt, $source, $sessionKey, $sendSms, $smsEvent) {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();

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
        });
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
