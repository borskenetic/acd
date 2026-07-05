const input = document.getElementById('qrcode');
const profileImg = document.getElementById('profileImg');
const syncBadge = document.getElementById('syncBadge');
const sectionModal = document.getElementById('sectionModal');
const sectionButtons = document.getElementById('sectionButtons');
const earlyOutAlarm = document.getElementById('earlyOutAlarm');
const earlyOutAlarmMessage = document.getElementById('earlyOutAlarmMessage');
const earlyOutAlarmTime = document.getElementById('earlyOutAlarmTime');
const scanNameDisplay = document.getElementById('scanNameDisplay');
const scanNameText = document.getElementById('scanNameText');
const scanStatusBadge = document.getElementById('scanStatusBadge');
const scanNameTimestamp = document.getElementById('scanNameTimestamp');

let selectedStudent = null;
let currentToken = null;
let clearDisplayTimer = null;
let isCooldown = false;
let gateSettings = { section_picker_enabled: false, attendance_sections: [] };

function tickClock() {
  const now = new Date();
  document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
  });
  document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', { hour12: false });
}

async function refreshStatus() {
  try {
    const res = await fetch('/api/status');
    const data = await res.json();
    if (data.online) {
      syncBadge.textContent = data.pending_count
        ? `Online — ${data.pending_count} scan(s) pending upload`
        : 'Online — synced';
      syncBadge.className = 'sync-badge sync-badge--online';
    } else {
      syncBadge.textContent = data.pending_count
        ? `Offline — ${data.pending_count} scan(s) queued`
        : 'Offline — scans saved locally';
      syncBadge.className = 'sync-badge sync-badge--offline';
    }
  } catch {
    syncBadge.textContent = 'Offline — scans saved locally';
    syncBadge.className = 'sync-badge sync-badge--offline';
  }
}

function clearDisplay() {
  earlyOutAlarm.hidden = true;
  scanNameDisplay.hidden = true;
}

function showDividerName(name, status, timestamp) {
  scanNameText.textContent = name;
  scanStatusBadge.textContent = status;
  scanStatusBadge.className = 'scan-status-badge ' + (status === 'OUT' ? 'is-out' : 'is-in');
  scanNameTimestamp.textContent = timestamp || '';
  scanNameDisplay.hidden = false;
}

function showEarlyOutAlarm(data) {
  earlyOutAlarmMessage.textContent = data.message || 'Checkout not allowed yet.';
  earlyOutAlarmTime.textContent = data.allowed_after || '';
  earlyOutAlarm.hidden = false;
  if (data.student) {
    showDividerName(`${data.student.firstname} ${data.student.lastname}`, 'BLOCKED', '');
  }
}

function scheduleClear(ms) {
  if (clearDisplayTimer) clearTimeout(clearDisplayTimer);
  clearDisplayTimer = setTimeout(clearDisplay, ms);
}

async function recordScan(token, section) {
  const res = await fetch('/api/scan/record', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ qrcode: token, section }),
  });
  const data = await res.json();
  if (!res.ok) throw data;
  return data;
}

async function handleStudentFlow(data, token) {
  selectedStudent = data.student;
  currentToken = token;

  if (data.next_status === 'OUT') {
    try {
      const response = await recordScan(token, null);
      showDividerName(`${selectedStudent.firstname} ${selectedStudent.lastname}`, 'OUT', response.scanned_at);
      scheduleClear(3000);
    } catch (err) {
      if (err.message) showEarlyOutAlarm({ message: err.message, allowed_after: err.allowed_after, student: selectedStudent });
    }
    return;
  }

  gateSettings.section_picker_enabled = data.section_picker_enabled;
  gateSettings.attendance_sections = data.attendance_sections || [];

  if (data.section_picker_enabled && gateSettings.attendance_sections.length) {
    sectionButtons.innerHTML = '';
    gateSettings.attendance_sections.forEach((section) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = section;
      btn.dataset.section = section;
      btn.addEventListener('click', () => confirmSection(section));
      sectionButtons.appendChild(btn);
    });
    sectionModal.hidden = false;
  } else {
    const response = await recordScan(token, null);
    showDividerName(`${selectedStudent.firstname} ${selectedStudent.lastname}`, response.status, response.scanned_at);
    scheduleClear(3000);
  }
}

async function confirmSection(section) {
  sectionModal.hidden = true;
  if (!currentToken) return;
  const response = await recordScan(currentToken, section);
  showDividerName(`${selectedStudent.firstname} ${selectedStudent.lastname}`, response.status, response.scanned_at);
  scheduleClear(3000);
  refreshStatus();
}

input.addEventListener('keypress', async (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  if (isCooldown) return;
  isCooldown = true;
  setTimeout(() => { isCooldown = false; }, 300);

  const token = input.value.trim().replace(/\r/g, '');
  input.value = '';
  if (!token) return;

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
      await handleStudentFlow(data, token);
      refreshStatus();
      return;
    }

    showEarlyOutAlarm({ message: data.message || 'ID not recognized.' });
  } catch (err) {
    showEarlyOutAlarm({ message: 'Scan failed. Try again.' });
    console.error(err);
  }
});

document.addEventListener('DOMContentLoaded', () => {
  tickClock();
  setInterval(tickClock, 1000);
  refreshStatus();
  setInterval(refreshStatus, 15000);
  input.focus();
});
