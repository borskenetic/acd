document.addEventListener('DOMContentLoaded', function () {
  const input = document.getElementById('qrcode');
  const profileImg = document.getElementById('profileImg');
  const sidebar = document.getElementById('scanSidebar');
  const syncBadge = document.getElementById('syncBadge');
  const scanAlarmSound = document.getElementById('scanAlarmSound');
  const sectionModal = document.getElementById('sectionModal');
  const sectionButtons = document.getElementById('sectionButtons');
  const earlyOutAlarm = document.getElementById('earlyOutAlarm');
  const earlyOutAlarmMessage = document.getElementById('earlyOutAlarmMessage');
  const earlyOutAlarmTime = document.getElementById('earlyOutAlarmTime');
  const testPanel = document.getElementById('testPanel');
  const testStudentSelect = document.getElementById('testStudentSelect');
  const testManualInput = document.getElementById('testManualInput');
  const testScanBtn = document.getElementById('testScanBtn');
  const gateVideo = document.getElementById('gateVideo');

  let selectedStudent = null;
  let currentScanToken = null;
  let clearDisplayTimer = null;
  let isCooldown = false;
  let cloudUrl = '';
  let appName = 'Assumption College of Davao';
  let gateSettings = {
    section_picker_enabled: false,
    attendance_sections: [],
  };

  const params = new URLSearchParams(window.location.search);
  let testMode = params.get('test') === '1' || localStorage.getItem('gate_test_mode') === '1';

  function setTestMode(on) {
    testMode = on;
    localStorage.setItem('gate_test_mode', on ? '1' : '0');
    testPanel.hidden = !on;
    if (on) loadTestStudents();
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'F2') {
      e.preventDefault();
      setTestMode(!testMode);
    }
  });

  setTestMode(testMode);

  setInterval(() => input.focus(), 100);
  input.focus();

  gateVideo?.addEventListener('error', () => {
    if (cloudUrl) {
      const src = `${cloudUrl.replace(/\/$/, '')}/videos/area51_product_slideshow.mp4`;
      gateVideo.innerHTML = `<source src="${src}" type="video/mp4">`;
      gateVideo.load();
    }
  });

  async function refreshStatus() {
    try {
      const res = await fetch('/api/status');
      const data = await res.json();
      cloudUrl = data.cloud_url || '';
      appName = data.app_name || appName;
      const footer = document.getElementById('footerAppName');
      if (footer) footer.textContent = `${appName} — Offline Gate`;

      if (data.online) {
        syncBadge.textContent = data.pending_count
          ? `Connected — ${data.pending_count} waiting to upload`
          : 'Connected — ready';
        syncBadge.className = 'gate-sync-badge gate-sync-badge--online';
      } else {
        syncBadge.textContent = data.pending_count
          ? `No internet — ${data.pending_count} saved locally`
          : 'No internet — scans still work';
        syncBadge.className = 'gate-sync-badge gate-sync-badge--offline';
      }
    } catch {
      syncBadge.textContent = 'No internet — scans still work';
      syncBadge.className = 'gate-sync-badge gate-sync-badge--offline';
    }
  }

  refreshStatus();
  setInterval(refreshStatus, 15000);

  async function loadTestStudents() {
    try {
      const res = await fetch('/api/test/students');
      const students = await res.json();
      testStudentSelect.innerHTML = '<option value="">Pick a student…</option>';
      students.forEach((s) => {
        const opt = document.createElement('option');
        const token = s.qrcode || s.rfid || s.student_id || '';
        opt.value = token;
        opt.textContent = `${s.lastname}, ${s.firstname} (${s.student_id || 'no ID'})`;
        testStudentSelect.appendChild(opt);
      });
    } catch (e) {
      console.warn('Could not load test students — sync first?', e);
    }
  }

  function profileUrl(path) {
    if (!path) {
      return '/images/2x2_undifined_gender.jpg';
    }
    if (path.startsWith('http')) return path;
    const clean = path.replace(/^\//, '');
    return `/media/${clean}`;
  }

  function showDividerName(name, status, timestamp, isOut) {
    const display = document.getElementById('scanNameDisplay');
    const nameEl = document.getElementById('scanNameText');
    const badgeEl = document.getElementById('scanStatusBadge');
    const tsEl = document.getElementById('scanNameTimestamp');
    if (!display) return;
    nameEl.textContent = name;
    badgeEl.textContent = status;
    badgeEl.className = 'scan-status-badge' + (isOut ? ' scan-status-out' : '');
    tsEl.textContent = timestamp || '';
    display.removeAttribute('hidden');
  }

  function hideDividerName() {
    const display = document.getElementById('scanNameDisplay');
    if (display) display.hidden = true;
  }

  function clearDisplay() {
    profileImg.src = '/images/2x2_undifined_gender.jpg';
    document.querySelectorAll('.name-box').forEach((box) => box.remove());
    hideDividerName();
    hideEarlyOutAlarm();
    selectedStudent = null;
    currentScanToken = null;
  }

  function playAlarmSound() {
    if (!scanAlarmSound) return;
    scanAlarmSound.currentTime = 0;
    scanAlarmSound.play().catch(() => {});
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

    profileImg.src = profileUrl(student.profile_picture);

    const div = document.createElement('div');
    div.classList.add('name-box', 'name-box--blocked');
    div.innerHTML = `
      <div class="student-name">${name}${year}</div>
      <div class="label">Still checked in</div>
      <div class="status-button status-blocked">NOT ALLOWED</div>
    `;
    sidebar.appendChild(div);

    earlyOutAlarm.hidden = false;
    scanSidebar?.classList.add('sidebar--alarm');
    playAlarmSound();
    scheduleClear(8000);
  }

  function showUnknownScanAlarm(message) {
    const div = document.createElement('div');
    div.classList.add('name-box', 'scan-error-box');
    div.innerHTML = `
      <div class="student-name">${message}</div>
      <div class="label">Not recognized</div>
      <div class="status-button status-blocked">UNKNOWN</div>
    `;
    sidebar.appendChild(div);
    scanSidebar?.classList.add('sidebar--alarm');
    playAlarmSound();
    scheduleClear(4000);
  }

  function hideEarlyOutAlarm() {
    earlyOutAlarm?.setAttribute('hidden', '');
    scanSidebar?.classList.remove('sidebar--alarm');
  }

  function scheduleClear(delayMs) {
    if (clearDisplayTimer) clearTimeout(clearDisplayTimer);
    clearDisplayTimer = setTimeout(clearDisplay, delayMs);
  }

  async function recordScan(token, section) {
    const res = await fetch('/api/scan/record', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ qrcode: token, section }),
    });
    const data = await res.json();
    if (!res.ok) {
      const err = new Error(data.message || 'Scan failed');
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  async function processScanToken(rawToken) {
    const token = String(rawToken || '').trim().replace(/\r/g, '');
    if (!token) return;

    if (isCooldown) return;
    isCooldown = true;
    setTimeout(() => { isCooldown = false; }, 300);

    clearDisplay();

    try {
      const res = await fetch('/api/scan', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ qrcode: token }),
      });
      const data = await res.json();

      if (data.type === 'early_out_blocked') {
        showEarlyOutAlarm(data);
        return;
      }

      if (data.type === 'student') {
        selectedStudent = data.student;
        currentScanToken = token;
        profileImg.src = profileUrl(data.student.profile_picture);

        gateSettings.section_picker_enabled = data.section_picker_enabled;
        gateSettings.attendance_sections = data.attendance_sections || [];

        if (data.next_status === 'OUT') {
          try {
            const response = await recordScan(token, null);
            const div = document.createElement('div');
            div.classList.add('name-box', 'scan-result-box');
            div.innerHTML = `
              <div class="student-name">${selectedStudent.firstname} ${selectedStudent.lastname}</div>
              <div class="label">Name</div>
              <div class="status-button status-out">OUT</div>
              <div class="timestamp">${response.scanned_at}</div>
            `;
            sidebar.appendChild(div);
            showDividerName(`${selectedStudent.firstname} ${selectedStudent.lastname}`, 'OUT', response.scanned_at, true);
            scheduleClear(2000);
          } catch (err) {
            if (err.status === 403) {
              showEarlyOutAlarm({
                message: err.data?.message,
                allowed_after: err.data?.allowed_after,
                student: selectedStudent,
              });
            }
          }
        } else {
          const sectionPickerOn = data.section_picker_enabled && gateSettings.attendance_sections.length > 0;
          if (sectionPickerOn) {
            sectionButtons.innerHTML = '';
            sectionButtons.dataset.count = String(gateSettings.attendance_sections.length);
            gateSettings.attendance_sections.forEach((section) => {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.dataset.section = section;
              btn.textContent = section;
              btn.addEventListener('click', () => confirmSection(section));
              sectionButtons.appendChild(btn);
            });
            sectionModal.style.display = 'flex';
            sectionModal.setAttribute('aria-hidden', 'false');
          } else {
            const response = await recordScan(token, null);
            const div = document.createElement('div');
            div.classList.add('name-box', 'scan-result-box');
            div.innerHTML = `
              <div class="student-name">${selectedStudent.firstname} ${selectedStudent.lastname}</div>
              <div class="label">Name</div>
              <div class="status-button">${response.status}</div>
              <div class="timestamp">${response.scanned_at}</div>
            `;
            sidebar.appendChild(div);
            showDividerName(`${selectedStudent.firstname} ${selectedStudent.lastname}`, response.status, response.scanned_at, false);
            scheduleClear(3000);
          }
        }

        refreshStatus();
        return;
      }

      showUnknownScanAlarm(data.message || 'ID not recognized. Sync roster when online, or use online gate for visitors.');
    } catch (err) {
      console.error(err);
      showUnknownScanAlarm('Scan failed. Try again.');
    }
  }

  async function confirmSection(section) {
    if (!currentScanToken || !selectedStudent) return;

    try {
      const response = await recordScan(currentScanToken, section);
      sectionModal.style.display = 'none';
      sectionModal.setAttribute('aria-hidden', 'true');

      const div = document.createElement('div');
      div.classList.add('name-box', 'scan-result-box');
      div.innerHTML = `
        <div class="student-name">${selectedStudent.firstname} ${selectedStudent.lastname}</div>
        <div class="label">${section}</div>
        <div class="status-button">${response.status}</div>
        <div class="timestamp">${response.scanned_at}</div>
      `;
      sidebar.appendChild(div);
      showDividerName(`${selectedStudent.firstname} ${selectedStudent.lastname}`, response.status, response.scanned_at, false);
      scheduleClear(3000);
      refreshStatus();
    } catch (err) {
      console.error(err);
    }
  }

  input.addEventListener('keypress', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const token = input.value;
    input.value = '';
    processScanToken(token);
  });

  testScanBtn?.addEventListener('click', () => {
    const token = testManualInput.value.trim() || testStudentSelect.value;
    if (!token) return;
    testManualInput.value = '';
    processScanToken(token);
    input.focus();
  });

  testStudentSelect?.addEventListener('change', () => {
    if (testStudentSelect.value) {
      testManualInput.value = testStudentSelect.value;
    }
  });

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
});
