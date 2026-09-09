<?php

namespace App\Console\Commands;

use App\Services\ModemSmsService;
use Illuminate\Console\Command;

class RetryPendingSmsCommand extends Command
{
    protected $signature = 'sms:retry-pending
                            {--failed-503 : Also requeue today\'s failed queue-full / connection errors}
                            {--limit= : Max rows to process this run}';

    protected $description = 'Retry pending (and optionally failed 503) modem SMS until the queue accepts them';

    public function handle(ModemSmsService $modem): int
    {
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        $counts = $modem->processDueRetries(
            limit: $limit,
            includeFailedRetryable: (bool) $this->option('failed-503'),
        );

        $this->info(sprintf(
            'SMS retries: %d sent, %d still waiting, %d gave up, %d skipped.',
            $counts['success'],
            $counts['retry'],
            $counts['give_up'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
