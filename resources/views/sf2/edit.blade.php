@extends('layouts.app')

@section('title', 'Edit SF2 Report')

@section('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/sf2-form.css') }}">
@endsection

@section('content')
<div class="mb-3">
    <a href="{{ route('sf2.show', $sf2) }}" class="text-decoration-none small">&larr; Back to report</a>
    <h4 class="mt-2 mb-1">Edit SF2 — {{ $sf2->titleLabel() }}</h4>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('sf2.update', $sf2) }}" id="sf2-form">
    @csrf
    @method('PUT')

    @include('sf2.partials.form-fields', ['report' => $sf2, 'defaults' => []])

    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Learners</span>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <input type="number" id="sf2-student-count" class="form-control form-control-sm" style="width:5rem" min="1" max="80">
                <button type="button" id="sf2-generate-rows" class="btn btn-sm btn-outline-primary">Generate rows</button>
                <button type="button" id="sf2-add-student" class="btn btn-sm btn-primary">Add learner</button>
            </div>
        </div>
        <div class="card-body">
            <div id="sf2-students-container">
                @php
                    $studentRows = old('students') ?? $sf2->students->map(fn ($s) => [
                        'sex' => $s->sex,
                        'last_name' => $s->last_name,
                        'first_name' => $s->first_name,
                        'middle_name' => $s->middle_name,
                        'remarks' => $s->remarks,
                        'absent_dates' => $s->absent_dates ?? [],
                        'tardy_dates' => $s->tardy_dates ?? [],
                        'half_day_dates' => $s->half_day_dates ?? [],
                    ])->all();
                @endphp
                @foreach($studentRows as $i => $student)
                    @include('sf2.partials.student-row-static', ['index' => $i, 'student' => $student])
                @endforeach
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Update report</button>
        <a href="{{ route('sf2.show', $sf2) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

@include('sf2.partials.student-row-template')
@endsection

@push('scripts')
<script src="{{ \App\Support\VersionedAsset::url('js/sf2-calendar.js') }}"></script>
<script src="{{ \App\Support\VersionedAsset::url('js/sf2-form.js') }}"></script>
@endpush
