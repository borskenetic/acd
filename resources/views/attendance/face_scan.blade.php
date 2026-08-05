<!DOCTYPE html>
<html lang="en">
<head>
  <title>{{ config('app.name') }} — Face Gate Terminal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="{{ \App\Support\Branding::stylesheetUrl() }}">
  <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/attendance/scan.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/attendance/face-scan.css') }}">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="scan-kiosk-page face-scan-page" data-face-model-cdn="{{ $faceModelCdn }}">

<header>
  <div class="header">
    <div class="logo-title">
      <img src="{{ asset('images/pantasLogo.png') }}" alt="Logo">
      <div class="system-title">Face Gate Terminal</div>
    </div>
    <a href="{{ route('attendance.scan') }}" class="scan-header-link ms-3">Gate terminal</a>
  </div>
</header>

<div id="kioskPairBanner" class="kiosk-pair-banner" hidden>
  <div class="kiosk-pair-banner__text">
    <strong data-kiosk-name>Kiosk</strong>
    <span data-kiosk-status></span>
  </div>
  <button type="button" class="kiosk-pair-banner__unpair" data-kiosk-unpair hidden>Unpair</button>
</div>

<div class="main">
  <div class="sidebar" id="scanSidebar">
    <div class="date" id="currentDate">Date</div>
    <div class="time" id="currentTime">--:--:--</div>
    <div class="profile-pic">
      <img src="{{ asset('images/2x2_undifined_gender.jpg') }}" alt="Default Profile">
    </div>
    <div id="earlyOutAlarm" class="early-out-alarm" hidden aria-live="assertive" role="alert">
      <div class="early-out-alarm__icon">⚠</div>
      <div class="early-out-alarm__title">Early checkout not allowed</div>
      <p class="early-out-alarm__message" id="earlyOutAlarmMessage"></p>
      <p class="early-out-alarm__hint">Allowed after <strong id="earlyOutAlarmTime">{{ $earlyDepartureCutoffLabel ?? '4:00 PM' }}</strong></p>
    </div>
  </div>

  <div class="right-content">
    <div class="face-scan-wrap">
      <span class="face-scan-badge">{{ $faceEnrolledCount }} enrolled</span>
      <video id="faceScanVideo" autoplay muted playsinline></video>
      <canvas id="faceScanCanvas"></canvas>
      <div class="face-scan-hint" id="faceScanHint">Starting camera…</div>
    </div>
  </div>
</div>

<footer>
  <div class="footer1">
    <div class="footer-logo">
      <div class="marquee-container">
        <div class="marquee">Welcome to {{ config('app.name') }}</div>
      </div>
    </div>
  </div>
</footer>

@include('attendance.partials.scan-modals', [
  'attendanceSections' => $attendanceSections ?? [],
])

<audio id="scanAlarmSound" src="{{ asset('sounds/alarm.wav') }}" preload="auto"></audio>

<script>
  window.KIOSK_PAIRING = {
    statusUrl: @json(route('attendance.kiosk.status')),
  };
  window.FACE_KIOSK_CONFIG = {
    identifyUrl: @json(route('attendance.face.identify')),
    sectionUrl: @json(route('attendance.section')),
    feedbackUrl: @json(route('attendance.feedback.store')),
    csrf: @json(csrf_token()),
    assetBase: @json(asset('')),
    defaultProfile: @json(asset('images/2x2_undifined_gender.jpg')),
    logoutFeedbackEnabled: @json($logoutFeedbackEnabled ?? false),
    sectionPickerEnabled: @json($sectionPickerEnabled ?? false),
    hasAttendanceSections: @json(count($attendanceSections ?? []) > 0),
    alarmSoundUrl: @json(asset('sounds/alarm.wav')),
  };
</script>
<script src="{{ \App\Support\VersionedAsset::url('js/kiosk-pairing.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.14/dist/face-api.js"></script>
<script src="{{ \App\Support\VersionedAsset::url('js/face-api-common.js') }}"></script>
<script src="{{ \App\Support\VersionedAsset::url('js/face-scan-kiosk.js') }}"></script>
</body>
</html>
