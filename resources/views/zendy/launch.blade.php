@extends('layouts.zen')

@section('page_title', 'Launching Zendy')
@section('page_subtitle', 'Redirecting you to the research platform')

@section('content')
<div class="card-surface" style="max-width: 520px; margin: 40px auto; text-align: center;">
    <div style="font-size: 2.5rem; margin-bottom: 16px;">↗</div>
    <h2 style="margin: 0 0 8px;">Opening Zendy</h2>
    <p style="color: var(--text-muted); margin: 0 0 24px;">
        You'll be redirected in a moment.
    </p>
    <a href="{{ $redirectUrl }}" id="zendyContinueLink" class="btn-app btn-primary-app">Continue now</a>
</div>
@endsection

@section('footer')
<script>
    window.zendySessionConfig = {
        endUrl: @json(route('zendy.session-end')),
        @if(!empty($clickId) && !empty($launchedAt))
        clickId: @json($clickId),
        launchedAt: @json($launchedAt),
        @else
        clearSession: true,
        @endif
    };

    var redirectUrl = @json($redirectUrl);

    function goToZendy() {
        if (window.zendySession) {
            window.zendySession.markNavigatingAway();
        }
        window.location.href = redirectUrl;
    }

    document.getElementById('zendyContinueLink').addEventListener('click', function (event) {
        event.preventDefault();
        goToZendy();
    });

    setTimeout(goToZendy, 2000);
</script>
@endsection
