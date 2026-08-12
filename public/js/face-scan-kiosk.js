/**
 * Face attendance kiosk — detects face, posts descriptor, runs same IN/OUT flow as QR scanner.
 */
(function () {
  const cfg = window.FACE_KIOSK_CONFIG || {};
  const video = document.getElementById('faceScanVideo');
  const canvas = document.getElementById('faceScanCanvas');
  const hint = document.getElementById('faceScanHint');
  const profileImg = document.querySelector('.profile-pic img');
  const sidebar = document.querySelector('.sidebar');
  const scanSidebar = document.getElementById('scanSidebar');
  const earlyOutAlarm = document.getElementById('earlyOutAlarm');
  const earlyOutAlarmMessage = document.getElementById('earlyOutAlarmMessage');
  const earlyOutAlarmTime = document.getElementById('earlyOutAlarmTime');
  const sectionModal = document.getElementById('sectionModal');
  const feedbackModal = document.getElementById('feedbackModal');
  const scanAlarmSound = document.getElementById('scanAlarmSound');

  let selectedStudent = null;
  let currentStudentId = null;
  let currentConfirmToken = null;
  let clearDisplayTimer = null;
  let isCooldown = false;
  let scanning = false;
  let lastSentAt = 0;

  function profileUrl(path) {
    return path ? cfg.assetBase + path.replace(/^\//, '') : cfg.defaultProfile;
  }

  function setHint(msg) {
    if (hint) hint.textContent = msg;
  }

  function clearDisplay() {
    if (feedbackModal && feedbackModal.style.display === 'flex') return;
    if (profileImg) profileImg.src = cfg.defaultProfile;
    document.querySelectorAll('.name-box').forEach((box) => box.remove());
    hideEarlyOutAlarm();
    selectedStudent = null;
    currentStudentId = null;
    currentConfirmToken = null;
  }

  function scheduleClear(delayMs) {
    if (clearDisplayTimer) clearTimeout(clearDisplayTimer);
    clearDisplayTimer = setTimeout(clearDisplay, delayMs);
  }

  function playAlarmSound() {
    if (scanAlarmSound) {
      scanAlarmSound.currentTime = 0;
      scanAlarmSound.play().catch(() => {});
      return;
    }
    if (!cfg.alarmSoundUrl) return;
    const audio = new Audio(cfg.alarmSoundUrl);
    audio.play().catch(() => {});
  }

  function hideEarlyOutAlarm() {
    earlyOutAlarm?.setAttribute('hidden', '');
    scanSidebar?.classList.remove('sidebar--alarm');
  }

  function showEarlyOutAlarm(data) {
    if (!earlyOutAlarm) return;
    const student = data.student || {};
    const name = [student.firstname, student.lastname].filter(Boolean).join(' ');
    const year = student.year ? ` (${student.year})` : '';
    if (earlyOutAlarmMessage) {
      earlyOutAlarmMessage.textContent = data.message || 'Cannot check out before the allowed time.';
    }
    if (earlyOutAlarmTime && data.allowed_after) {
      earlyOutAlarmTime.textContent = data.allowed_after;
    }
    const hint = earlyOutAlarm?.querySelector('.early-out-alarm__hint');
    if (hint) hint.hidden = !data.allowed_after;
    const title = earlyOutAlarm?.querySelector('.early-out-alarm__title');
    if (title) title.textContent = 'Early checkout not allowed';
    if (profileImg) profileImg.src = profileUrl(student.profile_picture);
    const div = document.createElement('div');
    div.classList.add('name-box', 'name-box--blocked');
    div.innerHTML = `
      <div class="student-name">${name}${year}</div>
      <div class="label">Still checked in</div>
      <div class="status-button status-blocked">NOT ALLOWED</div>
    `;
    sidebar?.appendChild(div);
    earlyOutAlarm.hidden = false;
    scanSidebar?.classList.add('sidebar--alarm');
    playAlarmSound();
    scheduleClear(8000);
    setHint('Look at the camera when ready.');
  }

  function showAlreadyScanned(data) {
    if (!earlyOutAlarm) {
      showUnknownScanAlarm(data.message || 'Already scanned.');
      return;
    }
    const student = data.student || {};
    const name = [student.firstname, student.lastname].filter(Boolean).join(' ');
    const year = student.year ? ` (${student.year})` : '';
    const status = (data.last_status || 'IN').toUpperCase();
    if (earlyOutAlarmMessage) {
      earlyOutAlarmMessage.textContent = data.message || `You have already scanned ${status}.`;
    }
    const hint = earlyOutAlarm?.querySelector('.early-out-alarm__hint');
    if (hint) hint.hidden = true;
    const title = earlyOutAlarm?.querySelector('.early-out-alarm__title');
    if (title) title.textContent = 'Already scanned';
    if (profileImg) profileImg.src = profileUrl(student.profile_picture);
    const div = document.createElement('div');
    div.classList.add('name-box', 'name-box--blocked');
    div.innerHTML = `
      <div class="student-name">${name}${year}</div>
      <div class="label">Already scanned</div>
      <div class="status-button status-blocked">ALREADY ${status}</div>
    `;
    sidebar?.appendChild(div);
    earlyOutAlarm.hidden = false;
    scanSidebar?.classList.add('sidebar--alarm');
    playAlarmSound();
    scheduleClear(5000);
    setHint('Look at the camera when ready.');
  }

  function showUnknownScanAlarm(message) {
    const div = document.createElement('div');
    div.classList.add('name-box', 'scan-error-box');
    div.innerHTML = `
      <div class="student-name">${message}</div>
      <div class="label">Not recognized</div>
      <div class="status-button status-blocked">UNKNOWN</div>
    `;
    sidebar?.appendChild(div);
    scanSidebar?.classList.add('sidebar--alarm');
    playAlarmSound();
    scheduleClear(4000);
    setHint('Look at the camera when ready.');
  }

  function showLogoutFeedback() {
    if (!cfg.logoutFeedbackEnabled || !feedbackModal || !currentStudentId) {
      scheduleClear(2000);
      return;
    }
    if (clearDisplayTimer) {
      clearTimeout(clearDisplayTimer);
      clearDisplayTimer = null;
    }
    setTimeout(() => {
      feedbackModal.style.display = 'flex';
      feedbackModal.setAttribute('aria-hidden', 'false');
    }, 500);
  }

  function closeFeedbackModal() {
    if (!feedbackModal) return;
    feedbackModal.style.display = 'none';
    feedbackModal.setAttribute('aria-hidden', 'true');
  }

  function processSection(section) {
    const body = {
      student_id: currentStudentId,
      section: section,
      confirm_token: currentConfirmToken,
    };
    const headers = {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': cfg.csrf,
      Accept: 'application/json',
    };
    return fetch(cfg.sectionUrl, {
      method: 'POST',
      headers: (window.KioskPairing && window.KioskPairing.headers(headers)) || headers,
      body: JSON.stringify(
        window.KioskPairing ? window.KioskPairing.attachBody(body) : body
      ),
    });
  }

  function handleScanResponse(data) {
    if (feedbackModal && feedbackModal.style.display === 'flex') {
      closeFeedbackModal();
    }
    clearDisplay();

    if (data.type === 'early_out_blocked') {
      showEarlyOutAlarm(data);
      return;
    }

    if (data.type === 'already_scanned') {
      showAlreadyScanned(data);
      return;
    }

    if (data.type === 'error') {
      showUnknownScanAlarm(data.message || 'Face not recognized. Please enroll or try again.');
      return;
    }

    if (data.type !== 'student') return;

    selectedStudent = data.student;
    currentStudentId = data.student_id;
    currentConfirmToken = data.confirm_token || null;
    if (profileImg) profileImg.src = profileUrl(data.student.profile_picture);

    if (data.next_status === 'OUT') {
      processSection(null).then(async (res) => {
        const response = await res.json();
        if (res.status === 403 || res.status === 409) {
          if (res.status === 409) {
            showAlreadyScanned({
              message: response.message,
              last_status: 'OUT',
              student: selectedStudent,
            });
          } else {
            showEarlyOutAlarm({
              message: response.message,
              allowed_after: response.allowed_after,
              student: selectedStudent,
            });
          }
          return;
        }
        const div = document.createElement('div');
        div.classList.add('name-box');
        div.innerHTML = `
          <div class="student-name">${selectedStudent.firstname} ${selectedStudent.lastname}</div>
          <div class="label">Name</div>
          <div class="status-button status-out">OUT</div>
          <div class="timestamp">${response.scanned_at}</div>
        `;
        sidebar?.appendChild(div);
        const feedbackOn = response.logout_feedback_enabled ?? data.logout_feedback_enabled ?? cfg.logoutFeedbackEnabled;
        if (feedbackOn) showLogoutFeedback();
        else scheduleClear(2000);
        setHint('Checked out. Look at camera for next scan.');
      });
      return;
    }

    const sectionPickerOn = (data.section_picker_enabled ?? cfg.sectionPickerEnabled) && cfg.hasAttendanceSections;
    if (sectionPickerOn && sectionModal) {
      sectionModal.style.display = 'flex';
      sectionModal.setAttribute('aria-hidden', 'false');
      setHint('Select section…');
    } else {
      processSection(null).then((res) => res.json()).then((response) => {
        const div = document.createElement('div');
        div.classList.add('name-box');
        div.innerHTML = `
          <div class="student-name">${selectedStudent.firstname} ${selectedStudent.lastname}</div>
          <div class="label">Name</div>
          <div class="status-button">${response.status}</div>
          <div class="timestamp">${response.scanned_at}</div>
        `;
        sidebar?.appendChild(div);
        scheduleClear(3000);
        setHint('Checked in. Look at camera for next scan.');
      });
    }
  }

  async function tryIdentify() {
    const now = Date.now();
    if (isCooldown || now - lastSentAt < 2500) return;
    if (sectionModal && sectionModal.style.display === 'flex') return;
    if (feedbackModal && feedbackModal.style.display === 'flex') return;

    const descriptor = await FaceApiHelper.getDescriptorFromVideo(video);
    if (!descriptor) {
      setHint('Look at the camera…');
      return;
    }

    isCooldown = true;
    lastSentAt = now;
    setHint('Recognizing…');

    try {
      const res = await fetch(cfg.identifyUrl, {
        method: 'POST',
        headers: (window.KioskPairing && window.KioskPairing.headers({
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': cfg.csrf,
          Accept: 'application/json',
        })) || {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': cfg.csrf,
          Accept: 'application/json',
        },
        body: JSON.stringify(
          window.KioskPairing
            ? window.KioskPairing.attachBody({ descriptor })
            : { descriptor }
        ),
      });
      const data = await res.json();
      handleScanResponse(data);
    } catch (e) {
      setHint('Network error. Retrying…');
    }

    setTimeout(() => { isCooldown = false; }, 1500);
  }

  async function detectionLoop() {
    if (!scanning) return;
    try {
      const detection = await faceapi
        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks(true);
      FaceApiHelper.drawDetection(canvas, video, detection);
      if (detection) await tryIdentify();
    } catch (e) { /* frame skip */ }
    setTimeout(detectionLoop, 400);
  }

  async function init() {
    if (!video) return;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
        audio: false,
      });
      video.srcObject = stream;
      await video.play();
      setHint('Loading face models…');
      await FaceApiHelper.loadModels(hint);
      scanning = true;
      setHint('Look at the camera to scan IN/OUT.');
      detectionLoop();
    } catch (e) {
      setHint(e.message || 'Camera access required.', true);
    }
  }

  document.querySelectorAll('.section-buttons button').forEach((btn) => {
    btn.addEventListener('click', function () {
      if (!currentStudentId) return;
      processSection(this.dataset.section).then((res) => res.json()).then((response) => {
        sectionModal.style.display = 'none';
        sectionModal.setAttribute('aria-hidden', 'true');
        const div = document.createElement('div');
        div.classList.add('name-box');
        div.innerHTML = `
          <div class="student-name">${selectedStudent.firstname} ${selectedStudent.lastname}</div>
          <div class="label">${this.dataset.section}</div>
          <div class="status-button">${response.status}</div>
          <div class="timestamp">${response.scanned_at}</div>
        `;
        sidebar?.appendChild(div);
        scheduleClear(3000);
        setHint('Look at the camera for next scan.');
      });
    });
  });

  function sendFeedback(rating, declined) {
    if (!currentStudentId) {
      closeFeedbackModal();
      clearDisplay();
      return;
    }
    fetch(cfg.feedbackUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': cfg.csrf,
        Accept: 'application/json',
      },
      body: JSON.stringify({ student_id: currentStudentId, rating, declined: declined ? 1 : 0 }),
    }).finally(() => {
      closeFeedbackModal();
      clearDisplay();
      setHint('Look at the camera when ready.');
    });
  }

  document.querySelectorAll('.feedback-options button').forEach((btn) => {
    btn.addEventListener('click', function () {
      sendFeedback(this.dataset.rating, 0);
    });
  });
  document.getElementById('declineFeedback')?.addEventListener('click', () => sendFeedback(null, 1));

  function updateDateTime() {
    const now = new Date();
    const dateEl = document.getElementById('currentDate');
    const timeEl = document.getElementById('currentTime');
    if (dateEl && timeEl) {
      dateEl.textContent = now.toLocaleDateString('en-GB', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
      });
      timeEl.textContent = now.toLocaleTimeString('en-US');
    }
  }
  updateDateTime();
  setInterval(updateDateTime, 1000);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
