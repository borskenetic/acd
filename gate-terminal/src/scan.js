const { v4: uuidv4 } = require('uuid');
const {
  findStudentByToken,
  getSettings,
  updateStudentLastLog,
  getTodayScans,
  insertLocalLog,
  countPending,
  setSyncState,
} = require('./db');

const TZ = 'Asia/Manila';

/** Philippine local time with +08:00 (avoids UTC offset in cloud uploads). */
function manilaLocalIso(date = new Date()) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: TZ,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(date)
      .map((p) => [p.type, p.value])
  );

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}+08:00`;
}

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

  const outAt = manilaLocalIso(endOfDay(last));
  updateStudentLastLog(student.cloud_id, 'OUT', outAt);

  return {
    ...student,
    last_log_status: 'OUT',
    last_log_scanned_at: outAt,
  };
}

function normalizeYear(year) {
  if (!year) return null;
  const y = String(year).trim().replace(/\s+/g, ' ');
  if (/^kinder(\s*[12])?$/i.test(y)) return 'Kinder';
  return y;
}

function resolveSchedule(student, settings) {
  const year = normalizeYear(student.year);
  if (!year) return null;
  const sessions = settings?.attendance_sessions || {};
  const schedules = sessions.schedules || {};
  for (const [key, schedule] of Object.entries(schedules)) {
    if ((schedule.years || []).includes(year)) {
      return { ...schedule, key };
    }
  }
  return null;
}

function isFriday(date = new Date()) {
  const wd = new Intl.DateTimeFormat('en-US', { timeZone: TZ, weekday: 'short' }).format(date);
  return wd === 'Fri';
}

function isHalfDayToday(student, schedule, settings, at = new Date()) {
  if (!schedule) return false;
  if (schedule.half_day) return true;
  return Boolean(settings?.attendance_sessions?.friday_half_day ?? true) && isFriday(at);
}

function todayAtTime(timeHHmm, at = new Date()) {
  if (!timeHHmm) return null;
  const [h, m] = String(timeHHmm).split(':').map(Number);
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: TZ,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    })
      .formatToParts(at)
      .map((p) => [p.type, p.value])
  );
  return new Date(`${parts.year}-${parts.month}-${parts.day}T${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:00+08:00`);
}

function expectedAction(count, halfDay) {
  if (count === 0) return { status: 'IN', session_key: 'morning_in', session_label: 'morning' };
  if (count === 1 && halfDay) return { status: 'OUT', session_key: 'half_day_out', session_label: 'morning' };
  if (count === 1) return { status: 'OUT', session_key: 'lunch_out', session_label: 'morning' };
  if (count === 2 && !halfDay) return { status: 'IN', session_key: 'afternoon_in', session_label: 'afternoon' };
  if (count === 3 && !halfDay) return { status: 'OUT', session_key: 'eod_out', session_label: 'afternoon' };
  return null;
}

function outAllowedAt(schedule, sessionKey, halfDay, at = new Date()) {
  let time = null;
  if (sessionKey === 'half_day_out') {
    time = halfDay && !schedule.half_day
      ? schedule.lunch_out
      : (schedule.half_day_out || schedule.lunch_out);
  } else if (sessionKey === 'lunch_out') {
    time = schedule.lunch_out;
  } else if (sessionKey === 'eod_out') {
    time = schedule.eod_out;
  }
  return todayAtTime(time, at);
}

function isWithinLunchWindow(schedule, halfDay, at = new Date()) {
  if (!schedule || halfDay || !schedule.lunch_out || !schedule.afternoon_in) return false;
  const lunch = todayAtTime(schedule.lunch_out, at);
  const afternoon = todayAtTime(schedule.afternoon_in, at);
  return at >= lunch && at < afternoon;
}

function cooldownMinutes(settings, schedule, halfDay, at = new Date()) {
  const sessions = settings?.attendance_sessions || {};
  if (isWithinLunchWindow(schedule, halfDay, at)) {
    return Number(sessions.lunch_cooldown_minutes ?? 5);
  }
  return Number(sessions.cooldown_minutes ?? 15);
}

function alreadyScannedMessage(status, sessionLabel) {
  const s = String(status).toUpperCase() === 'OUT' ? 'OUT' : 'IN';
  return `You have already scanned ${s} for the ${sessionLabel} session.`;
}

function decideSessionScan(student, settings, at = new Date()) {
  const schedule = resolveSchedule(student, settings);
  if (!schedule) return { type: 'not_session' };

  const halfDay = isHalfDayToday(student, schedule, settings, at);
  const scans = getTodayScans(student);
  const count = scans.length;
  const maxScans = halfDay ? 2 : 4;

  if (count >= maxScans) {
    const last = scans[scans.length - 1];
    const lastStatus = String(last?.status || 'OUT').toUpperCase();
    return {
      type: 'already_scanned',
      message: alreadyScannedMessage(lastStatus, halfDay ? 'today' : 'afternoon'),
      session_label: halfDay ? 'today' : 'afternoon',
      last_status: lastStatus,
    };
  }

  const last = scans[scans.length - 1];
  if (last?.scanned_at) {
    const lastAt = new Date(last.scanned_at);
    const mins = cooldownMinutes(settings, schedule, halfDay, at);
    if ((at - lastAt) / 1000 < mins * 60) {
      const lastStatus = String(last.status || 'IN').toUpperCase();
      const sessionLabel = count <= 2 ? 'morning' : 'afternoon';
      return {
        type: 'already_scanned',
        message: alreadyScannedMessage(lastStatus, sessionLabel),
        session_label: sessionLabel,
        last_status: lastStatus,
      };
    }
  }

  const expected = expectedAction(count, halfDay);
  if (!expected) {
    return {
      type: 'already_scanned',
      message: 'You have already completed scanning for today.',
      session_label: 'today',
    };
  }

  if (expected.status === 'OUT') {
    const allowedAt = outAllowedAt(schedule, expected.session_key, halfDay, at);
    if (allowedAt && at < allowedAt) {
      const label = allowedAt.toLocaleTimeString('en-US', {
        timeZone: TZ,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
      });
      return {
        type: 'early_out_blocked',
        message: `You are not yet allowed to scan OUT. Please try again after ${label}.`,
        allowed_after: label,
        next_status: 'OUT',
        session_key: expected.session_key,
        session_label: expected.session_label,
      };
    }
  }

  return {
    type: 'ok',
    next_status: expected.status,
    session_key: expected.session_key,
    session_label: expected.session_label,
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

  const levels = early.educational_levels || [];
  if (!levels.length) return false;
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

  const sessionDecision = decideSessionScan(student, settings);
  if (sessionDecision.type === 'already_scanned') {
    return {
      type: 'already_scanned',
      message: sessionDecision.message,
      session_label: sessionDecision.session_label,
      last_status: sessionDecision.last_status,
      student: studentPayload(student),
    };
  }

  if (sessionDecision.type === 'early_out_blocked') {
    return {
      type: 'early_out_blocked',
      message: sessionDecision.message,
      allowed_after: sessionDecision.allowed_after,
      student: studentPayload(student),
    };
  }

  if (sessionDecision.type === 'ok') {
    return {
      type: 'student',
      next_status: sessionDecision.next_status,
      session_key: sessionDecision.session_key,
      session_label: sessionDecision.session_label,
      student_id: student.cloud_id,
      section_picker_enabled: Boolean(settings.section_picker_enabled),
      logout_feedback_enabled: Boolean(settings.logout_feedback_enabled),
      student: studentPayload(student),
      attendance_sections: settings.attendance_sections || [],
    };
  }

  // SHS / College: simple toggle
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
  if (preview.type === 'already_scanned' || preview.type === 'early_out_blocked') {
    throw new Error(preview.message || 'Scan not allowed.');
  }
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
  const scannedAt = manilaLocalIso();
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
