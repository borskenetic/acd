const express = require('express');
const fs = require('fs');
const path = require('path');
const { previewScan, recordScan } = require('./scan');
const { loadConfig, runSyncCycle } = require('./sync');
const { getSyncState, countPending, db } = require('./db');

const config = loadConfig();
const app = express();
const port = config.port || 9173;
const publicDir = path.join(__dirname, '..', 'public');
const repoPublic = path.join(__dirname, '..', '..', 'public');
const repoVideos = path.join(__dirname, '..', '..', 'videos');

app.use(express.json());
app.use(express.static(publicDir));

if (fs.existsSync(path.join(repoPublic, 'images'))) {
  app.use('/images', express.static(path.join(repoPublic, 'images')));
}
if (fs.existsSync(path.join(repoPublic, 'videos'))) {
  app.use('/videos', express.static(path.join(repoPublic, 'videos')));
}
if (fs.existsSync(repoVideos)) {
  app.use('/videos', express.static(repoVideos));
}

function cloudMediaUrl(relativePath) {
  const base = String(config.cloud_url || '').replace(/\/$/, '');
  if (!base || !relativePath) return null;
  return `${base}/${String(relativePath).replace(/^\//, '')}`;
}

app.get('/media/*', (req, res) => {
  const relative = req.params[0];
  const localCandidates = [
    path.join(publicDir, relative),
    path.join(repoPublic, relative),
  ];

  for (const file of localCandidates) {
    if (fs.existsSync(file)) {
      return res.sendFile(file);
    }
  }

  const remote = cloudMediaUrl(relative);
  if (remote) {
    return res.redirect(remote);
  }

  return res.status(404).end();
});

app.get('/api/status', (_req, res) => {
  const state = getSyncState();
  res.json({
    online: Boolean(state.online),
    pending_count: countPending(),
    last_pull_at: state.last_pull_at,
    last_sync_at: state.updated_at,
    cloud_url: config.cloud_url || '',
    app_name: config.app_name || 'Assumption College of Davao',
  });
});

app.get('/api/test/students', (_req, res) => {
  const rows = db.prepare(`
    SELECT cloud_id, student_id, qrcode, rfid, firstname, lastname
    FROM students
    ORDER BY lastname, firstname
    LIMIT 100
  `).all();

  res.json(rows);
});

app.post('/api/scan', (req, res) => {
  const token = String(req.body?.qrcode || '').trim();
  if (!token) {
    return res.status(422).json({ type: 'error', message: 'QR code required.' });
  }

  res.json(previewScan(token));
});

app.post('/api/scan/record', (req, res) => {
  const token = String(req.body?.qrcode || '').trim();
  const section = req.body?.section ?? null;

  if (!token) {
    return res.status(422).json({ message: 'QR code required.' });
  }

  try {
    const result = recordScan(token, section);
    res.json({
      ...result,
      logout_feedback_enabled: false,
    });
  } catch (error) {
    const preview = previewScan(token);
    if (preview.type === 'early_out_blocked') {
      return res.status(403).json({
        message: preview.message,
        allowed_after: preview.allowed_after,
      });
    }

    return res.status(422).json({ message: error.message });
  }
});

app.post('/api/sync', async (_req, res) => {
  const result = await runSyncCycle(config);
  res.json(result);
});

async function startSyncLoop() {
  const intervalMs = (config.sync_interval_seconds || 60) * 1000;
  const tick = async () => {
    await runSyncCycle(config);
  };

  await tick();
  setInterval(tick, intervalMs);
}

app.listen(port, () => {
  console.log(`ACD gate terminal running at http://127.0.0.1:${port}`);
  console.log('Test mode: open with ?test=1 or press F2');
  startSyncLoop().catch((error) => {
    console.warn('Initial sync failed (offline mode):', error.message);
  });
});
