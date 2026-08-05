<?php

namespace App\Http\Controllers;

use App\Models\SchoolCalendarDay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolCalendarController extends Controller
{
    public function index(Request $request)
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $month = (int) $request->input('month', now($tz)->month);
        $year = (int) $request->input('year', now($tz)->year);
        $month = max(1, min(12, $month));
        $year = max(2000, min(2100, $year));

        $start = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $entries = SchoolCalendarDay::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get()
            ->keyBy(fn (SchoolCalendarDay $d) => $d->date->toDateString());

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $days[] = [
                'date' => $key,
                'weekday' => $d->format('D'),
                'is_weekend' => $d->isWeekend(),
                'entry' => $entries->get($key),
            ];
        }

        return view('school_calendar.index', [
            'days' => $days,
            'month' => $month,
            'year' => $year,
            'monthLabel' => $start->format('F Y'),
            'typeOptions' => SchoolCalendarDay::typeOptions(),
            'entries' => $entries,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'type' => ['required', Rule::in(array_keys(SchoolCalendarDay::typeOptions()))],
            'label' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        SchoolCalendarDay::updateOrCreate(
            ['date' => $validated['date']],
            [
                'type' => $validated['type'],
                'label' => $validated['label'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]
        );

        return back()->with('success', 'Calendar day saved.');
    }

    public function destroy(SchoolCalendarDay $schoolCalendar)
    {
        $schoolCalendar->delete();

        return back()->with('success', 'Calendar override removed. Day uses default weekday rules.');
    }
}
