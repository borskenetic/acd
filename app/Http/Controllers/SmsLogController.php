<?php

namespace App\Http\Controllers;

use App\Models\SmsLog;
use Illuminate\Http\Request;

class SmsLogController extends Controller
{
    public function index(Request $request)
    {
        $query = SmsLog::query()
            ->with(['student:id,firstname,lastname', 'user:id,fname,lname,email'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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
                    ->orWhere('error', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(40)->withQueryString();

        $typeOptions = SmsLog::query()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        $stats = [
            'success' => SmsLog::query()->where('status', SmsLog::STATUS_SUCCESS)->count(),
            'failed' => SmsLog::query()->where('status', SmsLog::STATUS_FAILED)->count(),
            'skipped' => SmsLog::query()->where('status', SmsLog::STATUS_SKIPPED)->count(),
            'today' => SmsLog::query()->whereDate('created_at', now()->toDateString())->count(),
        ];

        return view('sms.logs', [
            'logs' => $logs,
            'typeOptions' => $typeOptions,
            'typeLabels' => SmsLog::typeLabels(),
            'stats' => $stats,
        ]);
    }
}
