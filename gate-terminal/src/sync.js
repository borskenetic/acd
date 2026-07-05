const fs = require('fs');
const path = require('path');
const {
  getSyncState,
  setSyncState,
  saveSettings,
  upsertStudent,
  getPendingLogs,
  markSynced,
  countPending,
  applyRemoteLog,
} = require('./db');

function loadConfig() {
  const configPath = path.join(__dirname, '..', 'config.json');
  if (!fs.existsSync(configPath)) {
    throw new Error('Missing gate-terminal/config.json — copy config.example.json and set cloud_url + device_token.');
  }
  return JSON.parse(fs.readFileSync(configPath, 'utf8'));
}

function apiBase(config) {
  return String(config.cloud_url || '').replace(/\/$/, '') + '/api/gate';
}

async function cloudFetch(config, route, options = {}) {
  const url = apiBase(config) + route;
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${config.device_token}`,
    ...(options.headers || {}),
  };

  const response = await fetch(url, { ...options, headers });
  const text = await response.text();
  let body = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = { message: text };
  }

  if (!response.ok) {
    const message = body?.message || `HTTP ${response.status}`;
    throw new Error(message);
  }

  return body;
}

async function pullRoster(config) {
  const state = getSyncState();
  const since = state.last_pull_at ? `?since=${encodeURIComponent(state.last_pull_at)}` : '';
  const payload = await cloudFetch(config, `/roster${since}`);

  if (payload.settings) {
    saveSettings(payload.settings);
  }

  for (const student of payload.students || []) {
    upsertStudent(student);
  }

  for (const log of payload.logs_since || []) {
    applyRemoteLog(log.student_id, log.status, log.scanned_at);
  }

  setSyncState({
    last_pull_at: payload.server_time,
    online: 1,
    pending_count: countPending(),
  });

  return {
    students: (payload.students || []).length,
    full_snapshot: Boolean(payload.full_snapshot),
  };
}

async function pushAttendance(config) {
  const pending = getPendingLogs();
  if (pending.length === 0) {
    return { accepted: 0, rejected: 0 };
  }

  const body = {
    scans: pending.map((row) => ({
      client_uuid: row.client_uuid,
      scan_token: row.scan_token,
      status: row.status,
      section: row.section,
      scanned_at: row.scanned_at,
    })),
  };

  const result = await cloudFetch(config, '/attendance', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const acceptedUuids = (result.results || [])
    .filter((row) => row.accepted)
    .map((row) => row.client_uuid);

  if (acceptedUuids.length) {
    markSynced(acceptedUuids);
  }

  setSyncState({
    online: 1,
    pending_count: countPending(),
  });

  return {
    accepted: result.accepted || 0,
    rejected: result.rejected || 0,
  };
}

async function checkHealth(config) {
  await cloudFetch(config, '/health');
  setSyncState({ online: 1, pending_count: countPending() });
  return true;
}

async function runSyncCycle(config) {
  try {
    await checkHealth(config);
    await pullRoster(config);
    const push = await pushAttendance(config);
    return { ok: true, push };
  } catch (error) {
    setSyncState({ online: 0, pending_count: countPending() });
    return { ok: false, error: error.message };
  }
}

module.exports = {
  loadConfig,
  runSyncCycle,
  pullRoster,
  pushAttendance,
  checkHealth,
};
