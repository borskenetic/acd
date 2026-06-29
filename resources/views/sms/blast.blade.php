@extends('layouts.sec')

@section('content')

<div class="container mt-4">

    <h3>SMS Blast</h3>

    @if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
    @endif


    
    
    <form method="POST" action="{{ route('sms.send') }}">
        @csrf

        {{-- FILTER SECTION --}}
        <div class="row mb-3">

            <div class="col-md-6">
                <label>Filter by Year</label>
                <select name="year" id="yearFilter" class="form-control">
                    <option value="">All Years</option>
                    <option value="1">1st Year</option>
                    <option value="2">2nd Year</option>
                    <option value="3">3rd Year</option>
                    <option value="4">4th Year</option>
                </select>
            </div>

            <div class="col-md-6">
                <label>Filter by Course</label>
                <select name="course" id="courseFilter" class="form-control">

                    <option value="">All Courses</option>

                    @foreach($courses as $course)
                        <option value="{{ $course }}">{{ $course }}</option>
                    @endforeach

                </select>
            </div>

        </div>


        {{-- LIVE COUNTER --}}
        <div class="alert alert-info">

            Recipients: <b id="recipientCount">Loading...</b> students

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

    let year = document.getElementById('yearFilter').value;
    let course = document.getElementById('courseFilter').value;

    fetch("{{ route('sms.count') }}?year=" + year + "&course=" + course)
    .then(res => res.json())
    .then(data => {

        document.getElementById("recipientCount").innerText = data.count;

    });

}

document.getElementById('yearFilter').addEventListener('change', updateRecipientCount);
document.getElementById('courseFilter').addEventListener('change', updateRecipientCount);

window.onload = updateRecipientCount;

</script>

@endsection