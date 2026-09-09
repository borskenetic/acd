<?php

namespace App\Services;

use App\Jobs\SendModemSmsJob;
use App\Models\SmsLog;
use App\Models\StudentAttendanceAlertState;
use App\Models\StudentDailySms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Single entry point for modem SMS sends — every attempt is written to sms_logs.
 */
class ModemSmsService
{
    /**
     * @param  array{
     *   type?: string,
     *   student_id?: int|null,
     *   user_id?: int|null,
     *   recipient_label?: string|null,
     *   meta?: array<string, mixed>|null
     * }  $context
     */
    public function send(string $number, string $message, array $context = []): bool
    {
        return $this->deliver($number, $message, $context, withRetry: false) === 'success';
    }

    /**
     * Gate / alert path: try once, then keep retrying when the modem queue is full.
     *
     * Returns true only when the modem accepted the message (HTTP 2xx).
     * Pending retries apply the attendance claim from meta when they eventually succeed.
     *
     * @param  array{
     *   type?: string,
     *   student_id?: int|null,
     *   user_id?: int|null,
     *   recipient_label?: string|null,
     *   meta?: array<string, mixed>|null
     * }  $context
     */
    public function sendWithRetry(string $number, string $message, array $context = []): bool
    {
        return $this->deliver($number, $message, $context, withRetry: true) === 'success';
    }

    /**
     * @return 'success'|'pending'|'failed'|'skipped'
     */
    protected function deliver(string $number, string $message, array $context, bool $withRetry): string
    {
        $type = (string) ($context['type'] ?? 'unknown');
        $studentId = $context['student_id'] ?? null;
        $userId = $context['user_id'] ?? null;
        $label = $context['recipient_label'] ?? null;
        $meta = is_array($context['meta'] ?? null) ? $context['meta'] : null;

        $normalized = $this->normalizePhilippineMobile($number);

        if ($normalized === '') {
            $this->writeLog(
                toNumber: null,
                message: $message,
                type: $type,
                status: SmsLog::STATUS_SKIPPED,
                httpStatus: null,
                error: 'Missing or invalid mobile number',
                studentId: $studentId,
                userId: $userId,
                label: $label,
                meta: $meta,
            );

            return 'skipped';
        }

        // Avoid a second in-flight copy for the same student/event while retries run.
        if ($withRetry && is_numeric($studentId)) {
            $existingPending = SmsLog::query()
                ->where('student_id', (int) $studentId)
                ->where('type', $type !== '' ? $type : 'unknown')
                ->where('status', SmsLog::STATUS_PENDING)
                ->where('created_at', '>=', now()->subDay())
                ->orderByDesc('id')
                ->first();

            if ($existingPending) {
                return 'pending';
            }
        }

        $url = config('services.sms_modem.url') ?: env('SMS_MODEM_URL');
        $apiKey = config('services.sms_modem.key') ?: env('SMS_MODEM_API_KEY');

        if (! $url) {
            $this->writeLog(
                toNumber: $normalized,
                message: $message,
                type: $type,
                status: SmsLog::STATUS_FAILED,
                httpStatus: null,
                error: 'SMS modem URL is not configured (SMS_MODEM_URL)',
                studentId: $studentId,
                userId: $userId,
                label: $label,
                meta: $meta,
            );

            return 'failed';
        }

        try {
            $response = $this->postToModem($url, $apiKey, [
                ['number' => $normalized, 'message' => $message],
            ], 30);

            if ($response->successful()) {
                $this->writeLog(
                    toNumber: $normalized,
                    message: $message,
                    type: $type,
                    status: SmsLog::STATUS_SUCCESS,
                    httpStatus: $response->status(),
                    error: null,
                    studentId: $studentId,
                    userId: $userId,
                    label: $label,
                    meta: $meta,
                );

                return 'success';
            }

            $error = $this->formatHttpError($response);

            if ($withRetry && $this->isRetryableHttpStatus($response->status())) {
                $log = $this->writeLog(
                    toNumber: $normalized,
                    message: $message,
                    type: $type,
                    status: SmsLog::STATUS_PENDING,
                    httpStatus: $response->status(),
                    error: $error.' — queued for retry',
                    studentId: $studentId,
                    userId: $userId,
                    label: $label,
                    meta: $this->retryMeta($meta, attempt: 1),
                );

                if ($log) {
                    $this->dispatchRetry($log, $this->retryDelaySeconds(1));

                    return 'pending';
                }

                return 'failed';
            }

            $this->writeLog(
                toNumber: $normalized,
                message: $message,
                type: $type,
                status: SmsLog::STATUS_FAILED,
                httpStatus: $response->status(),
                error: $error,
                studentId: $studentId,
                userId: $userId,
                label: $label,
                meta: $meta,
            );

            return 'failed';
        } catch (\Throwable $e) {
            report($e);

            if ($withRetry && $this->isRetryableException($e)) {
                $log = $this->writeLog(
                    toNumber: $normalized,
                    message: $message,
                    type: $type,
                    status: SmsLog::STATUS_PENDING,
                    httpStatus: null,
                    error: $e->getMessage().' — queued for retry',
                    studentId: $studentId,
                    userId: $userId,
                    label: $label,
                    meta: $this->retryMeta($meta, attempt: 1),
                );

                if ($log) {
                    $this->dispatchRetry($log, $this->retryDelaySeconds(1));

                    return 'pending';
                }

                return 'failed';
            }

            $this->writeLog(
                toNumber: $normalized,
                message: $message,
                type: $type,
                status: SmsLog::STATUS_FAILED,
                httpStatus: null,
                error: $e->getMessage(),
                studentId: $studentId,
                userId: $userId,
                label: $label,
                meta: $meta,
            );

            return 'failed';
        }
    }

    /**
     * Re-attempt a pending (or requeued failed) log. Updates the same sms_logs row.
     *
     * @return 'success'|'retry'|'give_up'|'skipped'
     */
    public function attemptPendingDelivery(SmsLog $log): string
    {
        $lock = Cache::lock('sms-retry-'.$log->id, 45);
        if (! $lock->get()) {
            return 'retry';
        }

        try {
            $log->refresh();

            if ($log->status === SmsLog::STATUS_SUCCESS) {
                return 'success';
            }

            if ($log->status === SmsLog::STATUS_SKIPPED) {
                return 'skipped';
            }

            if (! in_array($log->status, [SmsLog::STATUS_PENDING, SmsLog::STATUS_FAILED], true)) {
                return 'give_up';
            }

            $meta = is_array($log->meta) ? $log->meta : [];
            $attempt = (int) ($meta['retry_attempt'] ?? 0);
            $maxAttempts = max(1, (int) config('services.sms_modem.retry_max_attempts', 60));

            if ($attempt >= $maxAttempts) {
                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'error' => trim((string) $log->error)." — gave up after {$maxAttempts} attempts",
                    'meta' => array_merge($meta, ['gave_up_at' => now()->toIso8601String()]),
                ]);

                return 'give_up';
            }

            $number = (string) ($log->to_number ?? '');
            $message = (string) $log->message;
            if ($number === '' || $message === '') {
                $log->update([
                    'status' => SmsLog::STATUS_SKIPPED,
                    'error' => 'Missing number or message',
                ]);

                return 'skipped';
            }

            $url = config('services.sms_modem.url') ?: env('SMS_MODEM_URL');
            $apiKey = config('services.sms_modem.key') ?: env('SMS_MODEM_API_KEY');

            if (! $url) {
                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'error' => 'SMS modem URL is not configured (SMS_MODEM_URL)',
                ]);

                return 'give_up';
            }

            $nextAttempt = $attempt + 1;

            try {
                $response = $this->postToModem($url, $apiKey, [
                    ['number' => $number, 'message' => $message],
                ], 30);

                if ($response->successful()) {
                    $log->update([
                        'status' => SmsLog::STATUS_SUCCESS,
                        'http_status' => $response->status(),
                        'error' => null,
                        'meta' => array_merge($this->retryMeta($meta, attempt: $nextAttempt), [
                            'delivered_at' => now()->toIso8601String(),
                        ]),
                    ]);

                    $this->applyAttendanceClaim($log->fresh() ?? $log);

                    return 'success';
                }

                $error = $this->formatHttpError($response);

                if ($this->isRetryableHttpStatus($response->status()) && $nextAttempt < $maxAttempts) {
                    $log->update([
                        'status' => SmsLog::STATUS_PENDING,
                        'http_status' => $response->status(),
                        'error' => $error.' — retrying',
                        'meta' => $this->retryMeta($meta, attempt: $nextAttempt),
                    ]);

                    return 'retry';
                }

                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'http_status' => $response->status(),
                    'error' => $error,
                    'meta' => $this->retryMeta($meta, attempt: $nextAttempt),
                ]);

                return 'give_up';
            } catch (\Throwable $e) {
                report($e);

                if ($this->isRetryableException($e) && $nextAttempt < $maxAttempts) {
                    $log->update([
                        'status' => SmsLog::STATUS_PENDING,
                        'http_status' => null,
                        'error' => $e->getMessage().' — retrying',
                        'meta' => $this->retryMeta($meta, attempt: $nextAttempt),
                    ]);

                    return 'retry';
                }

                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'http_status' => null,
                    'error' => $e->getMessage(),
                    'meta' => $this->retryMeta($meta, attempt: $nextAttempt),
                ]);

                return 'give_up';
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Process due pending rows (scheduler safety net / artisan).
     *
     * @return array{success: int, retry: int, give_up: int, skipped: int}
     */
    public function processDueRetries(?int $limit = null, bool $includeFailedRetryable = false): array
    {
        $limit ??= max(1, (int) config('services.sms_modem.retry_batch_size', 40));

        $query = SmsLog::query()
            ->where('status', SmsLog::STATUS_PENDING)
            ->orderBy('id')
            ->limit($limit);

        $logs = $query->get();

        if ($includeFailedRetryable && $logs->count() < $limit) {
            $maxAttempts = max(1, (int) config('services.sms_modem.retry_max_attempts', 60));

            $extra = SmsLog::query()
                ->where('status', SmsLog::STATUS_FAILED)
                ->where(function ($q) {
                    $q->where('http_status', 503)
                        ->orWhere('error', 'like', '%queue is full%')
                        ->orWhere('error', 'like', '%Connection%');
                })
                ->where(function ($q) {
                    $q->whereNull('error')
                        ->orWhere('error', 'not like', '%gave up%');
                })
                ->where('created_at', '>=', now()->subDay())
                ->orderBy('id')
                ->limit($limit - $logs->count())
                ->get()
                ->filter(function (SmsLog $log) use ($maxAttempts) {
                    $meta = is_array($log->meta) ? $log->meta : [];
                    $attempt = (int) ($meta['retry_attempt'] ?? 0);

                    return $attempt < $maxAttempts;
                });

            foreach ($extra as $log) {
                $meta = is_array($log->meta) ? $log->meta : [];
                $log->update([
                    'status' => SmsLog::STATUS_PENDING,
                    'error' => trim((string) preg_replace('/\s*—\s*requeued$/u', '', (string) $log->error)).' — requeued',
                    'meta' => $this->retryMeta($meta, attempt: (int) ($meta['retry_attempt'] ?? 0)),
                ]);
            }

            $logs = $logs->concat($extra->values());
        }

        $counts = ['success' => 0, 'retry' => 0, 'give_up' => 0, 'skipped' => 0];

        foreach ($logs as $log) {
            $result = $this->attemptPendingDelivery($log);
            if ($result === 'retry') {
                $log->refresh();
                $attempt = (int) ((is_array($log->meta) ? $log->meta['retry_attempt'] : null) ?? 1);
                $this->dispatchRetry($log, $this->retryDelaySeconds($attempt));
                $counts['retry']++;
            } elseif (isset($counts[$result])) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    public function dispatchRetry(SmsLog $log, int $delaySeconds = 20): void
    {
        SendModemSmsJob::dispatch($log->id)
            ->onConnection('database')
            ->delay(now()->addSeconds(max(5, $delaySeconds)));
    }

    public function retryDelaySeconds(int $attempt): int
    {
        $base = max(5, (int) config('services.sms_modem.retry_base_seconds', 20));
        $cap = max($base, (int) config('services.sms_modem.retry_max_seconds', 180));
        $exp = min(max(0, $attempt - 1), 4);

        return (int) min($cap, $base * (2 ** $exp));
    }

    public function isRetryableHttpStatus(?int $status): bool
    {
        return in_array($status, [408, 429, 500, 502, 503, 504], true);
    }

    public function isRetryableException(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || str_contains(strtolower($e->getMessage()), 'connection')
            || str_contains(strtolower($e->getMessage()), 'timed out');
    }

    /**
     * Bulk blast: one HTTP post for all messages, one log row per recipient.
     *
     * @param  list<array{number: string, message: string, student_id?: int|null, recipient_label?: string|null}>  $items
     * @return array{sent: int, failed: int}
     */
    public function sendBatch(array $items, array $context = []): array
    {
        $type = (string) ($context['type'] ?? 'blast');
        $userId = $context['user_id'] ?? null;
        $baseMeta = is_array($context['meta'] ?? null) ? $context['meta'] : [];

        $payload = [];
        $rows = [];

        foreach ($items as $item) {
            $message = (string) ($item['message'] ?? '');
            $normalized = $this->normalizePhilippineMobile((string) ($item['number'] ?? ''));
            $studentId = $item['student_id'] ?? null;
            $label = $item['recipient_label'] ?? null;

            if ($normalized === '' || $message === '') {
                $this->writeLog(
                    toNumber: $normalized !== '' ? $normalized : null,
                    message: $message !== '' ? $message : '(empty)',
                    type: $type,
                    status: SmsLog::STATUS_SKIPPED,
                    httpStatus: null,
                    error: 'Missing number or message',
                    studentId: $studentId,
                    userId: $userId,
                    label: $label,
                    meta: $baseMeta,
                );

                continue;
            }

            $payload[] = ['number' => $normalized, 'message' => $message];
            $rows[] = [
                'number' => $normalized,
                'message' => $message,
                'student_id' => $studentId,
                'recipient_label' => $label,
            ];
        }

        if ($payload === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $url = config('services.sms_modem.url') ?: env('SMS_MODEM_URL');
        $apiKey = config('services.sms_modem.key') ?: env('SMS_MODEM_API_KEY');

        if (! $url) {
            foreach ($rows as $row) {
                $this->writeLog(
                    toNumber: $row['number'],
                    message: $row['message'],
                    type: $type,
                    status: SmsLog::STATUS_FAILED,
                    httpStatus: null,
                    error: 'SMS modem URL is not configured (SMS_MODEM_URL)',
                    studentId: $row['student_id'],
                    userId: $userId,
                    label: $row['recipient_label'],
                    meta: $baseMeta,
                );
            }

            return ['sent' => 0, 'failed' => count($rows)];
        }

        try {
            $response = $this->postToModem($url, $apiKey, $payload, 300);

            $ok = $response->successful();
            $body = $response->body();
            $error = $ok ? null : ('Modem responded HTTP '.$response->status()
                .($body !== '' ? ': '.mb_substr($body, 0, 300) : ''));
            $status = $ok ? SmsLog::STATUS_SUCCESS : SmsLog::STATUS_FAILED;
            $httpStatus = $response->status();

            foreach ($rows as $row) {
                $this->writeLog(
                    toNumber: $row['number'],
                    message: $row['message'],
                    type: $type,
                    status: $status,
                    httpStatus: $httpStatus,
                    error: $error,
                    studentId: $row['student_id'],
                    userId: $userId,
                    label: $row['recipient_label'],
                    meta: $baseMeta + ['batch_size' => count($rows)],
                );
            }

            return $ok
                ? ['sent' => count($rows), 'failed' => 0]
                : ['sent' => 0, 'failed' => count($rows)];
        } catch (\Throwable $e) {
            report($e);

            foreach ($rows as $row) {
                $this->writeLog(
                    toNumber: $row['number'],
                    message: $row['message'],
                    type: $type,
                    status: SmsLog::STATUS_FAILED,
                    httpStatus: null,
                    error: $e->getMessage(),
                    studentId: $row['student_id'],
                    userId: $userId,
                    label: $row['recipient_label'],
                    meta: $baseMeta + ['batch_size' => count($rows)],
                );
            }

            return ['sent' => 0, 'failed' => count($rows)];
        }
    }

    /**
     * Retry TLS/connect drops that happen when many scans hit ngrok at once.
     *
     * @param  list<array{number: string, message: string}>  $payload
     */
    private function postToModem(string $url, ?string $apiKey, array $payload, int $timeout)
    {
        return Http::withHeaders(['X-API-KEY' => $apiKey])
            ->timeout($timeout)
            ->retry(4, 800, function ($exception) {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->post($url, $payload);
    }

    public function normalizePhilippineMobile(string $number): string
    {
        $number = preg_replace('/\s+/', '', $number) ?? '';

        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '0')) {
            return '+63'.substr($number, 1);
        }

        if (str_starts_with($number, '63')) {
            return '+'.$number;
        }

        return $number;
    }

    private function formatHttpError(Response $response): string
    {
        $body = $response->body();

        return 'Modem responded HTTP '.$response->status()
            .($body !== '' ? ': '.mb_substr($body, 0, 300) : '');
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function retryMeta(?array $meta, int $attempt): array
    {
        $meta = is_array($meta) ? $meta : [];

        return array_merge($meta, [
            'retry_attempt' => $attempt,
            'retryable' => true,
            'last_retry_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * When a deferred retry finally succeeds, mark the daily attendance SMS claim.
     */
    public function applyAttendanceClaim(SmsLog $log): void
    {
        $meta = is_array($log->meta) ? $log->meta : [];
        $claim = $meta['attendance_claim'] ?? null;
        if (! is_array($claim)) {
            return;
        }

        $studentId = isset($claim['student_id']) && is_numeric($claim['student_id'])
            ? (int) $claim['student_id']
            : null;
        $logDate = isset($claim['log_date']) && is_string($claim['log_date']) && $claim['log_date'] !== ''
            ? $claim['log_date']
            : null;
        $kind = isset($claim['kind']) && is_string($claim['kind']) ? $claim['kind'] : null;

        if ($studentId === null || $kind === null) {
            return;
        }

        try {
            if (in_array($kind, ['event', 'arrival', 'departure'], true)) {
                if ($logDate === null) {
                    return;
                }

                $daily = StudentDailySms::query()->firstOrCreate(
                    ['student_id' => $studentId, 'log_date' => $logDate],
                    ['arrival_sent' => false, 'departure_sent' => false, 'events_sent' => []]
                );

                if ($kind === 'arrival') {
                    if (! $daily->arrival_sent) {
                        $daily->update(['arrival_sent' => true]);
                    }

                    return;
                }

                if ($kind === 'departure') {
                    if (! $daily->departure_sent) {
                        $daily->update(['departure_sent' => true]);
                    }

                    return;
                }

                $event = isset($claim['event']) && is_string($claim['event']) ? $claim['event'] : null;
                if ($event === null || $event === '') {
                    return;
                }

                $sent = $daily->events_sent ?? [];
                if (! is_array($sent)) {
                    $sent = [];
                }
                if (! in_array($event, $sent, true)) {
                    $sent[] = $event;
                    $daily->update(['events_sent' => array_values(array_unique($sent))]);
                }

                return;
            }

            if ($kind === 'consecutive_late' || $kind === 'consecutive_absent') {
                $count = isset($claim['streak_count']) && is_numeric($claim['streak_count'])
                    ? (int) $claim['streak_count']
                    : null;
                if ($count === null) {
                    return;
                }

                $state = StudentAttendanceAlertState::query()->firstOrCreate(
                    ['student_id' => $studentId],
                    ['late_streak_notified' => 0, 'absent_streak_notified' => 0]
                );

                if ($kind === 'consecutive_late' && $state->late_streak_notified < $count) {
                    $state->update(['late_streak_notified' => $count]);
                }

                if ($kind === 'consecutive_absent' && $state->absent_streak_notified < $count) {
                    $state->update(['absent_streak_notified' => $count]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function writeLog(
        ?string $toNumber,
        string $message,
        string $type,
        string $status,
        ?int $httpStatus,
        ?string $error,
        mixed $studentId,
        mixed $userId,
        mixed $label,
        ?array $meta,
    ): ?SmsLog {
        try {
            return SmsLog::query()->create([
                'to_number' => $toNumber,
                'message' => $message,
                'type' => $type !== '' ? $type : 'unknown',
                'status' => $status,
                'http_status' => $httpStatus,
                'error' => $error,
                'student_id' => is_numeric($studentId) ? (int) $studentId : null,
                'user_id' => is_numeric($userId) ? (int) $userId : null,
                'recipient_label' => is_string($label) && $label !== '' ? $label : null,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
