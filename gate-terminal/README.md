# ACD Offline Gate Terminal

Local gate app for student QR/RFID scanning when internet is unavailable. Scans are stored in SQLite and uploaded to the cloud Laravel app when connectivity returns.

**Visitors are not supported offline** — use the online gate at `/attendance` for visitor passes.

## Prerequisites

- Node.js 18+
- Running ACD online app with gate sync API enabled
- A gate device token from **Admin → Gate Devices**

## Setup

1. Copy config:

   ```bash
   cd gate-terminal
   copy config.example.json config.json
   ```

2. Edit `config.json`:

   ```json
   {
     "cloud_url": "https://your-acd-server.example.com",
     "device_token": "gate_....",
     "port": 9173,
     "sync_interval_seconds": 60
   }
   ```

3. Install and run:

   ```bash
   npm install
   npm start
   ```

4. Open **http://127.0.0.1:9173** full-screen on the gate PC.

## How it works

| Step | Behavior |
|------|----------|
| Startup | Pulls student roster + gate settings from `GET /api/gate/roster` |
| Scan | Looks up student locally, toggles IN/OUT, queues log in SQLite |
| Online | Pushes queued scans to `POST /api/gate/attendance` every 60s |
| Offline | Scans still work; badge shows pending upload count |

## Manual sync

```bash
npm run sync
```

## Cloud admin

Register devices at **Admin → Gate Devices**. Copy the one-time token into `config.json`.

Sync API (Bearer token):

- `GET /api/gate/health`
- `GET /api/gate/roster?since=ISO8601`
- `POST /api/gate/attendance`

## Assets (optional)

Place branding assets in `public/assets/`:

- `logo.png`
- `default-profile.jpg`

If missing, the UI still works with text-only header.
