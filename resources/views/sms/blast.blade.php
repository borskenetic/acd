@extends('layouts.sec')

@section('content')

<div class="container mt-4">

    <h3>SMS Blast</h3>

    @if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger">
        {{ session('error') }}
    </div>
    @endif

    <form method="POST" action="{{ route('sms.send') }}">
        @csrf

        <div class="row mb-3 g-3">

            <div class="col-md-3">
                <label for="recipientFilter">Send to</label>
                <select name="recipient" id="recipientFilter" class="form-control" required>
                    <option value="emergency_contact" @selected(old('recipient', 'emergency_contact') === 'emergency_contact')>
                        Emergency contact (parent/guardian)
                    </option>
                    <option value="student" @selected(old('recipient') === 'student')>
                        Student mobile number
                    </option>
                </select>
            </div>

            <div class="col-md-3">
                <label for="yearFilter">Filter by Year / Grade</label>
                <select name="year" id="yearFilter" class="form-control">
                    <option value="">All years / grades</option>
                    @foreach($yearOptions as $year)
                        <option value="{{ $year }}" @selected(old('year') === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label for="sectionFilter">Filter by Section</label>
                <select name="section" id="sectionFilter" class="form-control">
                    <option value="">All sections</option>
                    @foreach($sections as $section)
                        <option value="{{ $section }}" @selected(old('section') === $section)>{{ $section }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label for="courseFilter">Filter by Course / Strand</label>
                <select name="course" id="courseFilter" class="form-control">
                    <option value="">All Courses</option>
                    @foreach($courses as $course)
                        <option value="{{ $course }}" @selected(old('course') === $course)>{{ $course }}</option>
                    @endforeach
                </select>
            </div>

        </div>

        <div class="alert alert-info">
            Recipients: <b id="recipientCount">Loading...</b> <span id="recipientLabel">emergency contacts</span>
            @if(!empty($facultyLocked))
                <div class="small mt-1">
                    Faculty scope: SMS only reaches your <strong>adviser</strong> classes
                    @if(!empty($facultyClasses))
                        ({{ collect($facultyClasses)->map(fn($c) => $c['year'].' · '.$c['section'])->implode('; ') }})
                    @endif.
                </div>
            @endif
        </div>

        <div class="mb-3">
            <label>Message</label>
            <textarea
                name="message"
                class="form-control"
                rows="5"
                placeholder="Example: Hello {name}, please visit the library today."
                required
            ></textarea>
            <small class="text-muted">
                Available variables:
                <br><b>{name}</b> = Student full name
            </small>
        </div>

        <button class="btn btn-primary">
            Send SMS
        </button>

        <a href="{{ route('sms.scanMessage') }}" class="btn btn-outline-secondary ms-2">
            Gate SMS settings
        </a>
    </form>

</div>

<script>
const sectionsByGrade = @json($sectionsByGrade ?? new \stdClass());

function rebuildSectionOptions() {
    const year = document.getElementById('yearFilter').value;
    const sectionSelect = document.getElementById('sectionFilter');
    const current = sectionSelect.value;
    const allSections = @json($sections);

    let list = allSections;
    if (year && sectionsByGrade[year] && sectionsByGrade[year].length) {
        list = sectionsByGrade[year];
    }

    sectionSelect.innerHTML = '<option value="">All sections</option>';
    list.forEach(function (sec) {
        const opt = document.createElement('option');
        opt.value = sec;
        opt.textContent = sec;
        if (sec === current) opt.selected = true;
        sectionSelect.appendChild(opt);
    });
}

function updateRecipientCount(){
    const year = document.getElementById('yearFilter').value;
    const course = document.getElementById('courseFilter').value;
    const section = document.getElementById('sectionFilter').value;
    const recipient = document.getElementById('recipientFilter').value;
    const labels = {
        emergency_contact: 'emergency contacts',
        student: 'students with mobile numbers',
    };

    const params = new URLSearchParams({
        year: year,
        course: course,
        section: section,
        recipient: recipient,
    });

    fetch("{{ route('sms.count') }}?" + params.toString())
    .then(res => res.json())
    .then(data => {
        document.getElementById("recipientCount").innerText = data.count;
        document.getElementById("recipientLabel").innerText = labels[recipient] || '';
    });
}

document.getElementById('yearFilter').addEventListener('change', function () {
    rebuildSectionOptions();
    updateRecipientCount();
});
document.getElementById('courseFilter').addEventListener('change', updateRecipientCount);
document.getElementById('sectionFilter').addEventListener('change', updateRecipientCount);
document.getElementById('recipientFilter').addEventListener('change', updateRecipientCount);

window.onload = function () {
    rebuildSectionOptions();
    updateRecipientCount();
};
</script>

@endsection
