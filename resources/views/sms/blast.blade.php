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

        {{-- FILTER SECTION --}}
        <div class="row mb-3">

            <div class="col-md-4">
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

            <div class="col-md-4">
                <label for="yearFilter">Filter by Year / Grade</label>
                <select name="year" id="yearFilter" class="form-control">
                    <option value="">All years / grades</option>
                    @foreach($yearOptions as $year)
                        <option value="{{ $year }}" @selected(old('year') === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label for="courseFilter">Filter by Course</label>
                <select name="course" id="courseFilter" class="form-control">
                    <option value="">All Courses</option>
                    @foreach($courses as $course)
                        <option value="{{ $course }}" @selected(old('course') === $course)>{{ $course }}</option>
                    @endforeach
                </select>
            </div>

        </div>


        {{-- LIVE COUNTER --}}
        <div class="alert alert-info">

            Recipients: <b id="recipientCount">Loading...</b> <span id="recipientLabel">emergency contacts</span>

        </div>


        {{-- MESSAGE --}}
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


{{-- JAVASCRIPT FOR LIVE COUNT --}}
<script>

function updateRecipientCount(){
    const year = document.getElementById('yearFilter').value;
    const course = document.getElementById('courseFilter').value;
    const recipient = document.getElementById('recipientFilter').value;
    const labels = {
        emergency_contact: 'emergency contacts',
        student: 'students with mobile numbers',
    };

    fetch("{{ route('sms.count') }}?year=" + encodeURIComponent(year) + "&course=" + encodeURIComponent(course) + "&recipient=" + encodeURIComponent(recipient))
    .then(res => res.json())
    .then(data => {
        document.getElementById("recipientCount").innerText = data.count;
        document.getElementById("recipientLabel").innerText = labels[recipient] || '';
    });
}

document.getElementById('yearFilter').addEventListener('change', updateRecipientCount);
document.getElementById('courseFilter').addEventListener('change', updateRecipientCount);
document.getElementById('recipientFilter').addEventListener('change', updateRecipientCount);

window.onload = updateRecipientCount;

</script>

@endsection