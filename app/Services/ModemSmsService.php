<?php

namespace App\Services;

use App\Models\SmsLog;
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

            return false;
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

            return false;
        }

        try {
            $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                ->timeout(30)
                ->post($url, [
                    ['number' => $normalized, 'message' => $message],
                ]);

            $ok = $response->successful();
            $body = $response->body();
            $error = $ok ? null : ('Modem responded HTTP '.$response->status()
                .($body !== '' ? ': '.mb_substr($body, 0, 300) : ''));

            $this->writeLog(
                toNumber: $normalized,
                message: $message,
                type: $type,
                status: $ok ? SmsLog::STATUS_SUCCESS : SmsLog::STATUS_FAILED,
                httpStatus: $response->status(),
                error: $error,
                studentId: $studentId,
                userId: $userId,
                label: $label,
                meta: $meta,
            );

            return $ok;
        } catch (\Throwable $e) {
            report($e);

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

            return false;
        }
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
            $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                ->timeout(300)
                ->post($url, $payload);

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
    ): void {
        try {
            SmsLog::query()->create([
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
        }
    }
}
