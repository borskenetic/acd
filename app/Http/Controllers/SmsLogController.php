<?php

namespace App\Http\Controllers;

use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class SmsLogController extends Controller
{
    public function index(Request $request)
    {
        $baseQuery = $this->filteredLogs($request);

        $logs = (clone $baseQuery)
            ->paginate(40)
            ->withQueryString();

        $typeOptions = SmsLog::query()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return view('sms.logs', [
            'logs' => $logs,
            'typeOptions' => $typeOptions,
            'typeLabels' => SmsLog::typeLabels(),
            'stats' => $this->summaryForQuery($request),
        ]);
    }

    /** @return array{matching: int, sent: int, failed: int, today: int} */
    private function summaryForQuery(Request $request): array
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();

        $matching = $this->filteredLogs($request);
        $withoutStatus = $this->filteredLogs($request, ignoreStatus: true);

        return [
            'matching' => (clone $matching)->count(),
            'sent' => (clone $withoutStatus)->where('status', SmsLog::STATUS_SUCCESS)->count(),
            'failed' => (clone $withoutStatus)
                ->whereIn('status', [SmsLog::STATUS_FAILED, SmsLog::STATUS_SKIPPED])
                ->count(),
            'today' => (clone $withoutStatus)->whereDate('created_at', $today)->count(),
        ];
    }

    private function filteredLogs(Request $request, bool $ignoreStatus = false): Builder
    {
        $query = SmsLog::query()
            ->with(['student:id,firstname,lastname', 'user:id,fname,lname,email'])
            ->orderByDesc('created_at');

        if (! $ignoreStatus && $request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'sent') {
                $status = SmsLog::STATUS_SUCCESS;
            }
            $query->where('status', $status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('to_number', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhere('recipient_label', 'like', "%{$search}%")
                    ->orWhere('error', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($student) use ($search) {
                        $student->where('firstname', 'like', "%{$search}%")
                            ->orWhere('lastname', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }
}
