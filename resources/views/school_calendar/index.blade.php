@extends('layouts.sec')

@section('title', 'School calendar')

@section('content')
<div class="container py-4" style="max-width: 960px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h3 class="mb-0">School calendar</h3>
            <p class="text-muted small mb-0">
                Mark holidays, forced school days, or special “otherwise” non-class days.
                SF2 and absence tracking skip holidays / otherwise days.
            </p>
        </div>
        <a href="{{ route('attendance.policy.settings') }}" class="btn btn-outline-secondary btn-sm">Attendance policy</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="GET" class="row g-2 mb-3 align-items-end">
        <div class="col-auto">
            <label class="form-label mb-0 small">Month</label>
            <select name="month" class="form-select form-select-sm">
                @for($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected($month === $m)>{{ \Carbon\Carbon::create(null, $m, 1)->format('F') }}</option>
                @endfor
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label mb-0 small">Year</label>
            <input type="number" name="year" class="form-control form-control-sm" value="{{ $year }}" min="2000" max="2100">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-sm">Go</button>
        </div>
        <div class="col-auto ms-auto">
            <span class="fw-semibold">{{ $monthLabel }}</span>
        </div>
    </form>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Set / update a day</div>
        <div class="card-body">
            <form method="POST" action="{{ route('school_calendar.store') }}" class="row g-3">
                @csrf
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" value="{{ old('date') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select" required>
                        @foreach($typeOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Label (optional)</label>
                    <input type="text" name="label" class="form-control" value="{{ old('label') }}" placeholder="e.g. Independence Day">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" value="{{ old('notes') }}">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary btn-sm">Save day</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Weekday</th>
                    <th>Default</th>
                    <th>Override</th>
                    <th>Label</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($days as $day)
                    @php
                        $entry = $day['entry'];
                        $default = $day['is_weekend'] ? 'Weekend' : 'School day';
                    @endphp
                    <tr class="@if($entry && $entry->type === 'holiday') table-warning @elseif($entry && $entry->type === 'school_day') table-success @elseif($entry && $entry->type === 'otherwise') table-secondary @endif">
                        <td>{{ \Carbon\Carbon::parse($day['date'])->format('M j, Y') }}</td>
                        <td>{{ $day['weekday'] }}</td>
                        <td>{{ $default }}</td>
                        <td>
                            @if($entry)
                                {{ $entry->typeLabel() }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $entry?->label }}</td>
                        <td class="text-end">
                            @if($entry)
                                <form method="POST" action="{{ route('school_calendar.destroy', $entry) }}" class="d-inline"
                                      onsubmit="return confirm('Remove this calendar override?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Clear</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
