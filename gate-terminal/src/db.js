const Database = require('better-sqlite3');
const fs = require('fs');
const path = require('path');

const dataDir = path.join(__dirname, '..', 'data');
if (!fs.existsSync(dataDir)) {
  fs.mkdirSync(dataDir, { recursive: true });
}

const dbPath = path.join(dataDir, 'gate.db');
const db = new Database(dbPath);

db.pragma('journal_mode = WAL');

db.exec(`
  CREATE TABLE IF NOT EXISTS sync_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    last_pull_at TEXT,
    pending_count INTEGER NOT NULL DEFAULT 0,
    online INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT
  );

  INSERT OR IGNORE INTO sync_state (id, pending_count, online) VALUES (1, 0, 0);

  CREATE TABLE IF NOT EXISTS settings_cache (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    payload TEXT NOT NULL DEFAULT '{}',
    updated_at TEXT
  );

  INSERT OR IGNORE INTO settings_cache (id, payload) VALUES (1, '{}');

  CREATE TABLE IF NOT EXISTS students (
    cloud_id INTEGER PRIMARY KEY,
    record_id TEXT,
    student_id TEXT,
    qrcode TEXT,
    rfid TEXT,
    firstname TEXT NOT NULL,
    lastname TEXT NOT NULL,
    middle_initial TEXT,
    normalized_name TEXT,
    profile_picture TEXT,
    educational_level TEXT,
    year TEXT,
    last_log_status TEXT,
    last_log_scanned_at TEXT,
    updated_at TEXT
  );

  CREATE INDEX IF NOT EXISTS idx_students_qrcode ON students(qrcode);
  CREATE INDEX IF NOT EXISTS idx_students_rfid ON students(rfid);
  CREATE INDEX IF NOT EXISTS idx_students_student_id ON students(student_id);
  CREATE INDEX IF NOT EXISTS idx_students_normalized_name ON students(normalized_name);

  CREATE TABLE IF NOT EXISTS local_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_uuid TEXT NOT NULL UNIQUE,
    cloud_student_id INTEGER NOT NULL,
    scan_token TEXT NOT NULL,
    status TEXT NOT NULL,
    section TEXT,
    scanned_at TEXT NOT NULL,
    synced_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE INDEX IF NOT EXISTS idx_local_logs_sync ON local_logs(synced_at);
`);

try {
  db.exec('ALTER TABLE students ADD COLUMN today_scans_json TEXT');
} catch (_) {
  // column already exists
}

function getSyncState() {
  return db.prepare('SELECT * FROM sync_state WHERE id = 1').get();
}

function setSyncState(patch) {
  const current = getSyncState();
  const next = { ...current, ...patch, updated_at: new Date().toISOString() };
  db.prepare(`
    UPDATE sync_state
    SET last_pull_at = @last_pull_at,
        pending_count = @pending_count,
        online = @online,
        updated_at = @updated_at
    WHERE id = 1
  `).run({
    last_pull_at: next.last_pull_at ?? current.last_pull_at,
    pending_count: next.pending_count ?? current.pending_count,
    online: next.online ?? current.online,
    updated_at: next.updated_at,
  });
}

function getSettings() {
  const row = db.prepare('SELECT payload FROM settings_cache WHERE id = 1').get();
  return row ? JSON.parse(row.payload) : {};
}

function saveSettings(payload) {
  db.prepare(`
    UPDATE settings_cache SET payload = ?, updated_at = ? WHERE id = 1
  `).run(JSON.stringify(payload), new Date().toISOString());
}

function upsertStudent(student) {
  db.prepare(`
    INSERT INTO students (
      cloud_id, record_id, student_id, qrcode, rfid, firstname, lastname, middle_initial,
      normalized_name, profile_picture, educational_level, year,
      last_log_status, last_log_scanned_at, today_scans_json, updated_at
    ) VALUES (
      @cloud_id, @record_id, @student_id, @qrcode, @rfid, @firstname, @lastname, @middle_initial,
      @normalized_name, @profile_picture, @educational_level, @year,
      @last_log_status, @last_log_scanned_at, @today_scans_json, @updated_at
    )
    ON CONFLICT(cloud_id) DO UPDATE SET
      record_id = excluded.record_id,
      student_id = excluded.student_id,
      qrcode = excluded.qrcode,
      rfid = excluded.rfid,
      firstname = excluded.firstname,
      lastname = excluded.lastname,
      middle_initial = excluded.middle_initial,
      normalized_name = excluded.normalized_name,
      profile_picture = excluded.profile_picture,
      educational_level = excluded.educational_level,
      year = excluded.year,
      last_log_status = excluded.last_log_status,
      last_log_scanned_at = excluded.last_log_scanned_at,
      today_scans_json = excluded.today_scans_json,
      updated_at = excluded.updated_at
  `).run({
    cloud_id: student.id,
    record_id: student.record_id ?? null,
    student_id: student.student_id ?? null,
    qrcode: student.qrcode ?? null,
    rfid: student.rfid ?? null,
    firstname: student.firstname,
    lastname: student.lastname,
    middle_initial: student.middle_initial ?? null,
    normalized_name: student.normalized_name ?? null,
    profile_picture: student.profile_picture ?? null,
    educational_level: student.educational_level ?? null,
    year: student.year ?? null,
    last_log_status: student.last_log?.status ?? null,
    last_log_scanned_at: student.last_log?.scanned_at ?? null,
    today_scans_json: JSON.stringify(student.today_scans || []),
    updated_at: new Date().toISOString(),
  });
}

function findStudentByToken(raw) {
  const token = String(raw || '').trim().replace(/\r/g, '');
  if (!token) return null;

  let row = db.prepare('SELECT * FROM students WHERE qrcode = ?').get(token);
  if (row) return row;

  row = db.prepare('SELECT * FROM students WHERE rfid = ?').get(token);
  if (row) return row;

  const parsed = parseQr(raw);
  if (parsed.student_no) {
    row = db.prepare('SELECT * FROM students WHERE student_id = ?').get(parsed.student_no);
    if (row) return row;
  }

  if (parsed.full_name) {
    const normalized = normalizeName(parsed.full_name);
    row = db.prepare('SELECT * FROM students WHERE normalized_name = ?').get(normalized);
    if (row) return row;
  }

  return null;
}

function parseQr(raw) {
  const text = String(raw || '').trim().replace(/\r/g, '');
  if (text.includes('\n')) {
    const lines = text.split('\n').map((l) => l.trim()).filter(Boolean);
    return { student_no: lines[0] || null, full_name: lines[1] || null, course: lines[2] || null };
  }

  const parts = text.split(',').map((p) => p.trim());
  if (/^\d{2}-\d+$/.test(parts[0] || '')) {
    return { student_no: parts[0] || null, full_name: parts[1] || null, course: parts[2] || null };
  }

  return { student_no: null, full_name: parts[0] || null, course: parts[1] || null };
}

function normalizeName(name) {
  return String(name || '')
    .trim()
    .replace(/\s+/g, ' ')
    .split(' ')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(' ');
}

function updateStudentLastLog(cloudId, status, scannedAt) {
  const student = db.prepare('SELECT * FROM students WHERE cloud_id = ?').get(cloudId);
  let todayScans = [];
  try {
    todayScans = student?.today_scans_json ? JSON.parse(student.today_scans_json) : [];
  } catch (_) {
    todayScans = [];
  }

  const today = manilaDateString(scannedAt);
  // Drop scans from other days, then append.
  todayScans = (Array.isArray(todayScans) ? todayScans : []).filter((s) => manilaDateString(s.scanned_at) === today);
  todayScans.push({ status, scanned_at: scannedAt });

  db.prepare(`
    UPDATE students
    SET last_log_status = ?, last_log_scanned_at = ?, today_scans_json = ?, updated_at = ?
    WHERE cloud_id = ?
  `).run(status, scannedAt, JSON.stringify(todayScans), new Date().toISOString(), cloudId);
}

function manilaDateString(isoOrDate) {
  const d = isoOrDate ? new Date(isoOrDate) : new Date();
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(d);
}

function getTodayScans(student) {
  let scans = [];
  try {
    scans = student?.today_scans_json ? JSON.parse(student.today_scans_json) : [];
  } catch (_) {
    scans = [];
  }
  const today = manilaDateString();
  return (Array.isArray(scans) ? scans : []).filter((s) => manilaDateString(s.scanned_at) === today);
}

function insertLocalLog(entry) {
  db.prepare(`
    INSERT INTO local_logs (client_uuid, cloud_student_id, scan_token, status, section, scanned_at)
    VALUES (@client_uuid, @cloud_student_id, @scan_token, @status, @section, @scanned_at)
  `).run(entry);
}

function countPending() {
  return db.prepare('SELECT COUNT(*) AS c FROM local_logs WHERE synced_at IS NULL').get().c;
}

function getPendingLogs(limit = 500) {
  return db.prepare(`
    SELECT * FROM local_logs WHERE synced_at IS NULL ORDER BY scanned_at ASC LIMIT ?
  `).all(limit);
}

function markSynced(clientUuids) {
  const stmt = db.prepare(`UPDATE local_logs SET synced_at = ? WHERE client_uuid = ?`);
  const now = new Date().toISOString();
  const tx = db.transaction((uuids) => {
    for (const uuid of uuids) {
      stmt.run(now, uuid);
    }
  });
  tx(clientUuids);
}

function applyRemoteLog(cloudStudentId, status, scannedAt) {
  const student = db.prepare('SELECT * FROM students WHERE cloud_id = ?').get(cloudStudentId);
  if (!student) return;

  const current = student.last_log_scanned_at ? new Date(student.last_log_scanned_at) : null;
  const incoming = new Date(scannedAt);
  if (!current || incoming >= current) {
    updateStudentLastLog(cloudStudentId, status, scannedAt);
  }
}

module.exports = {
  db,
  getSyncState,
  setSyncState,
  getSettings,
  saveSettings,
  upsertStudent,
  findStudentByToken,
  updateStudentLastLog,
  getTodayScans,
  manilaDateString,
  insertLocalLog,
  countPending,
  getPendingLogs,
  markSynced,
  applyRemoteLog,
};
