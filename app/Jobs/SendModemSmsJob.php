<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\ModemSmsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendModemSmsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 80;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public int $smsLogId)
    {
        $this->onConnection('database');
    }

    public function uniqueId(): string
    {
        return 'sms-log-'.$this->smsLogId;
    }

    public function handle(ModemSmsService $modem): void
    {
        $log = SmsLog::query()->find($this->smsLogId);
        if (! $log) {
            return;
        }

        if ($log->status === SmsLog::STATUS_SUCCESS) {
            return;
        }

        $result = $modem->attemptPendingDelivery($log);

        if ($result === 'retry') {
            $log->refresh();
            $attempt = (int) ((is_array($log->meta) ? $log->meta['retry_attempt'] : null) ?? 1);
            $this->release($modem->retryDelaySeconds($attempt));
        }
    }
}
