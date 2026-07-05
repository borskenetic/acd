const express = require('express');
const path = require('path');
const { previewScan, recordScan } = require('./scan');
const { loadConfig, runSyncCycle } = require('./sync');
const { getSyncState, countPending } = require('./db');

const config = loadConfig();
const app = express();
const port = config.port || 9173;

app.use(express.json());
app.use(express.static(path.join(__dirname, '..', 'public')));

app.get('/api/status', (_req, res) => {
  const state = getSyncState();
  res.json({
    online: Boolean(state.online),
    pending_count: countPending(),
    last_pull_at: state.last_pull_at,
    last_sync_at: state.updated_at,
  });
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
    res.json(result);
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
  console.log('Open this URL full-screen on the gate PC.');
  startSyncLoop().catch((error) => {
    console.warn('Initial sync failed (offline mode):', error.message);
  });
});
