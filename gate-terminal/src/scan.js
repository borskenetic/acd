const { v4: uuidv4 } = require('uuid');
const {
  findStudentByToken,
  getSettings,
  updateStudentLastLog,
  insertLocalLog,
  countPending,
  setSyncState,
} = require('./db');

const TZ = 'Asia/Manila';

function isInStatus(status) {
  return status != null && String(status).trim().toLowerCase() === 'in';
}

function startOfDay(date) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  return d;
}

function endOfDay(date) {
  const d = new Date(date);
  d.setHours(23, 59, 59, 999);
  return d;
}

function closeStaleOpenIn(student) {
  if (!student.last_log_status || !isInStatus(student.last_log_status) || !student.last_log_scanned_at) {
    return student;
  }

  const last = new Date(student.last_log_scanned_at);
  const todayStart = startOfDay(new Date());

  if (startOfDay(last) >= todayStart) {
    return student;
  }

  const outAt = endOfDay(last).toISOString();
  updateStudentLastLog(student.cloud_id, 'OUT', outAt);

  return {
    ...student,
    last_log_status: 'OUT',
    last_log_scanned_at: outAt,
  };
}

function getLogoutCutoffToday(settings) {
  const logoutTime = settings?.early_departure?.logout_time || settings?.attendance_policy?.logout_time || '16:00';
  const [hours, minutes] = logoutTime.split(':').map(Number);
  const now = new Date();
  const cutoff = new Date(now);
  cutoff.setHours(hours, minutes, 0, 0);
  return cutoff;
}

function blocksCheckout(student, settings, at = new Date()) {
  const early = settings?.early_departure || {};
  if (!early.enabled) return false;

  const levels = early.educational_levels || ['grade_school'];
  if (!student.educational_level || !levels.includes(student.educational_level)) {
    return false;
  }

  return at < getLogoutCutoffToday(settings);
}

function formatDisplayTime(iso) {
  return new Date(iso).toLocaleString('en-US', {
    timeZone: TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  });
}

function previewScan(rawToken) {
  const settings = getSettings();
  let student = findStudentByToken(rawToken);

  if (!student) {
    return {
      type: 'error',
      message: 'ID not recognized. Sync the roster when online, or use the online gate for visitors.',
    };
  }

  student = closeStaleOpenIn(student);

  const lastIn = isInStatus(student.last_log_status);
  const nextStatus = lastIn ? 'OUT' : 'IN';

  if (nextStatus === 'OUT' && blocksCheckout(student, settings)) {
    const allowedAfter = settings?.early_departure?.earliest_out_label || '4:00 PM';
    const message = (settings?.early_departure?.message || 'Checkout not allowed before {time}.')
      .replace('{time}', allowedAfter);

    return {
      type: 'early_out_blocked',
      message,
      allowed_after: allowedAfter,
      student: studentPayload(student),
    };
  }

  return {
    type: 'student',
    next_status: nextStatus,
    student_id: student.cloud_id,
    section_picker_enabled: Boolean(settings.section_picker_enabled),
    logout_feedback_enabled: Boolean(settings.logout_feedback_enabled),
    student: studentPayload(student),
    attendance_sections: settings.attendance_sections || [],
  };
}

function recordScan(rawToken, section = null) {
  const preview = previewScan(rawToken);
  if (preview.type !== 'student') {
    throw new Error(preview.message || 'Scan not allowed.');
  }

  const settings = getSettings();
  const sections = settings.attendance_sections || [];
  if (section && !sections.includes(section)) {
    throw new Error('Invalid section selected.');
  }

  const student = findStudentByToken(rawToken);
  const status = preview.next_status;
  const scannedAt = new Date().toISOString();
  const clientUuid = uuidv4();

  insertLocalLog({
    client_uuid: clientUuid,
    cloud_student_id: student.cloud_id,
    scan_token: String(rawToken).trim().replace(/\r/g, ''),
    status,
    section: section || null,
    scanned_at: scannedAt,
  });

  updateStudentLastLog(student.cloud_id, status, scannedAt);
  setSyncState({ pending_count: countPending() });

  return {
    status,
    scanned_at: formatDisplayTime(scannedAt),
    client_uuid: clientUuid,
    logout_feedback_enabled: Boolean(settings.logout_feedback_enabled),
  };
}

function studentPayload(student) {
  return {
    id: student.cloud_id,
    firstname: student.firstname,
    lastname: student.lastname,
    profile_picture: student.profile_picture,
    year: student.year,
    educational_level: student.educational_level,
  };
}

module.exports = {
  previewScan,
  recordScan,
  formatDisplayTime,
};
