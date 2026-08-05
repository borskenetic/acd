<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\GateDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Retroactively set kiosk_name on past attendance scans.
 *
 * Automatic: rows that already have gate_device_id get the current device name.
 * Manual: assign a name/device to unnamed rows in a date range (use when only one
 * kiosk ran, or you know which terminal covered that period).
 */
class BackfillAttendanceKioskNames extends Command
{
    protected $signature = 'attendance:backfill-kiosk-names
                            {--from= : Only logs scanned on/after this date (Y-m-d)}
                            {--to= : Only logs scanned on/before this date (Y-m-d)}
                            {--device= : Gate device id — set kiosk_name and gate_device_id}
                            {--name= : Kiosk name text (does not create a device unless used with --device)}
                            {--source=* : Limit sources (default: web,web_kiosk,gate_sync). Use --source=* for all}
                            {--only-empty : Only rows with empty kiosk_name (default true; pass --only-empty=0 to overwrite)}
                            {--from-devices : Fill name from existing gate_device_id only}
                            {--dry-run : Show counts without writing}';

    protected $description = 'Retroactively set kiosk names on past attendance logs';

    public function handle(): int
    {
        if (! Schema::hasColumn('attendance_logs', 'kiosk_name')) {
            $this->error('Column kiosk_name is missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        $fromDevices = (bool) $this->option('from-devices');
        $deviceId = $this->option('device');
        $name = $this->option('name');
        $dryRun = (bool) $this->option('dry-run');
        $onlyEmpty = $this->option('only-empty') === null
            || filter_var($this->option('only-empty'), FILTER_VALIDATE_BOOLEAN);

        if (! $fromDevices && $deviceId === null && $name === null) {
            // Default convenience: copy names onto rows that already know the device.
            $fromDevices = true;
            $this->line('No --device / --name given — filling from existing gate_device_id only.');
            $this->line('To assign a name to old browser scans, re-run with e.g.:');
            $this->line('  php artisan attendance:backfill-kiosk-names --name="Main Gate" --from=2026-06-01 --to=2026-08-04');
            $this->newLine();
        }

        if ($deviceId !== null) {
            $device = GateDevice::query()->find($deviceId);
            if (! $device) {
                $this->error("Gate device id {$deviceId} not found.");

                return self::FAILURE;
            }
            $name = $name ?: $device->name;
        }

        if ($fromDevices) {
            return $this->fillFromDevices($onlyEmpty, $dryRun);
        }

        if (! filled($name)) {
            $this->error('Provide --name=... or --device=...');

            return self::FAILURE;
        }

        return $this->assignName(
            name: (string) $name,
            deviceId: $deviceId !== null ? (int) $deviceId : null,
            onlyEmpty: $onlyEmpty,
            dryRun: $dryRun,
        );
    }

    private function fillFromDevices(bool $onlyEmpty, bool $dryRun): int
    {
        $devices = GateDevice::query()->get(['id', 'name']);
        if ($devices->isEmpty()) {
            $this->warn('No gate devices registered. Create kiosks first, or use --name= / --device=.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($devices as $device) {
            $query = AttendanceLog::query()->where('gate_device_id', $device->id);
            $this->applyDateFilters($query);
            if ($onlyEmpty) {
                $query->where(function ($q) {
                    $q->whereNull('kiosk_name')->orWhere('kiosk_name', '');
                });
            }

            $count = (clone $query)->count();
            if ($count === 0) {
                continue;
            }

            $this->line("Device #{$device->id} “{$device->name}”: {$count} log(s)");
            if (! $dryRun) {
                $query->update(['kiosk_name' => $device->name]);
            }
            $total += $count;
        }

        $this->info(($dryRun ? '[dry-run] Would update' : 'Updated')." {$total} log(s) from gate_device_id.");

        return self::SUCCESS;
    }

    private function assignName(string $name, ?int $deviceId, bool $onlyEmpty, bool $dryRun): int
    {
        $query = AttendanceLog::query();
        $this->applyDateFilters($query);
        $this->applySourceFilters($query);

        if ($onlyEmpty) {
            $query->where(function ($q) {
                $q->whereNull('kiosk_name')->orWhere('kiosk_name', '');
            });
            // Prefer not to re-point rows already linked to a different device.
            if ($deviceId !== null) {
                $query->where(function ($q) use ($deviceId) {
                    $q->whereNull('gate_device_id')->orWhere('gate_device_id', $deviceId);
                });
            } else {
                $query->whereNull('gate_device_id');
            }
        }

        $count = (clone $query)->count();
        $this->line("Matching logs: {$count}");
        $this->line('Name: '.$name.($deviceId ? " (device #{$deviceId})" : ' (name only, no device link)'));

        if ($count === 0) {
            $this->warn('Nothing to update.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would set kiosk_name on {$count} log(s).");

            return self::SUCCESS;
        }

        if (! $this->confirm("Set kiosk name to “{$name}” on {$count} log(s)?", true)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $payload = ['kiosk_name' => $name];
        if ($deviceId !== null) {
            $payload['gate_device_id'] = $deviceId;
        }

        $updated = $query->update($payload);
        $this->info("Updated {$updated} log(s).");

        return self::SUCCESS;
    }

    private function applyDateFilters($query): void
    {
        if ($this->option('from')) {
            $query->whereDate('scanned_at', '>=', $this->option('from'));
        }
        if ($this->option('to')) {
            $query->whereDate('scanned_at', '<=', $this->option('to'));
        }
    }

    private function applySourceFilters($query): void
    {
        $sources = $this->option('source');
        if ($sources === [] || $sources === null) {
            $query->whereIn('source', ['web', 'web_kiosk', 'gate_sync']);

            return;
        }

        // Allow --source=* via shell may pass literal *
        if (count($sources) === 1 && ($sources[0] === '*' || $sources[0] === 'all')) {
            return;
        }

        $query->whereIn('source', $sources);
    }
}
