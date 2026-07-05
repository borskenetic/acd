<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GateDevice;
use App\Services\GateRosterService;
use App\Services\StudentScanService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GateSyncController extends Controller
{
    public function health(Request $request)
    {
        /** @var GateDevice $device */
        $device = $request->attributes->get('gate_device');

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toIso8601String(),
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            ],
        ]);
    }

    public function roster(Request $request, GateRosterService $roster)
    {
        $since = null;
        if ($request->filled('since')) {
            try {
                $since = Carbon::parse($request->query('since'));
            } catch (\Throwable) {
                return response()->json(['message' => 'Invalid since timestamp.'], 422);
            }
        }

        return response()->json($roster->build($since));
    }

    public function pushAttendance(Request $request, StudentScanService $scanService)
    {
        /** @var GateDevice $device */
        $device = $request->attributes->get('gate_device');

        $validated = $request->validate([
            'scans' => 'required|array|min:1|max:500',
            'scans.*.client_uuid' => 'required|uuid|distinct',
            'scans.*.scan_token' => 'required|string|max:500',
            'scans.*.status' => 'required|in:IN,OUT,in,out',
            'scans.*.section' => 'nullable|string|max:255',
            'scans.*.scanned_at' => 'required|date',
        ]);

        $results = [];

        foreach ($validated['scans'] as $row) {
            $clientUuid = $row['client_uuid'];
            $student = $scanService->resolveStudent($row['scan_token']);

            if (! $student) {
                $results[] = [
                    'client_uuid' => $clientUuid,
                    'accepted' => false,
                    'error' => 'Student not found for scan token.',
                ];

                continue;
            }

            try {
                $scannedAt = Carbon::parse($row['scanned_at']);
                $log = $scanService->recordSyncedScan(
                    $student,
                    strtoupper($row['status']),
                    $scannedAt,
                    $row['section'] ?? null,
                    $clientUuid,
                    $device,
                );

                $results[] = [
                    'client_uuid' => $clientUuid,
                    'accepted' => true,
                    'attendance_log_id' => $log->id,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'client_uuid' => $clientUuid,
                    'accepted' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $device->touchSynced();

        $accepted = collect($results)->where('accepted', true)->count();
        $rejected = count($results) - $accepted;

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'accepted' => $accepted,
            'rejected' => $rejected,
            'results' => $results,
        ]);
    }
}
