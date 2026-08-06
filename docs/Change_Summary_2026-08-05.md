# Change summary — 5 August 2026

Tasks completed for **ACD Online** (Assumption College of Davao attendance / SMS / SF2).

---

## 1. Named kiosks (JMC-style scan location)

**Goal:** Each terminal has a name; attendance logs show **where** the student scanned.

### What was done
- Reused **Gate devices** as named **kiosks** (Admin → Kiosks).
- On each successful scan, store:
  - `gate_device_id`
  - `kiosk_name` (snapshot so renames don’t erase history)
- **Browser kiosks** (QR + face): pair via one-time token / “Open as this name” link; token kept in browser `localStorage` and sent on every scan.
- Offline gate-terminal apps continue using Bearer device tokens; synced scans also get kiosk name.
- Attendance Logs: **Kiosk** column, filter by device/name, Excel + PDF include kiosk.
- UI: pairing banner on gate terminals (paired / unnamed / invalid token).
- **New token** action to rotate pairing if a PC is shared or lost.

### Migration
- `2026_08_05_000004_add_kiosk_name_to_attendance_logs_table`

### Deploy notes
```bash
php artisan migrate --force
```
After deploy: create kiosk → pair each terminal PC once with the open link / token.

---

## 2. Retroactive kiosk names (backfill)

**Goal:** Label older scans when history had no device linking.

### What was done
- Artisan command: `attendance:backfill-kiosk-names`
- **Auto:** rows already with `gate_device_id` → copy current device name into `kiosk_name`.
- **Manual:** assign a name or device for a date range (for past `web` scans that had no device).

### Examples
```bash
# Preview
php artisan attendance:backfill-kiosk-names --name="Main Gate" --dry-run

# Apply
php artisan attendance:backfill-kiosk-names --name="Main Gate"

# By date / registered device
php artisan attendance:backfill-kiosk-names --device=1 --from=2026-01-01 --to=2026-08-04 --dry-run
php artisan attendance:backfill-kiosk-names --from-devices
```

There is **no way to invent** which physical gate old browser-only scans used; backfill requires a known name or date range.

---

## 3. SF2 export aligned to official ACD workbook

**Goal:** Excel export matches the school’s **SF2.xlsx** (SF2-SHS multi-month file).

### What was done
- Replaced template with school file:  
  `resources/templates/sf2/sf2-template.xlsx` (also `docs/sf2-official-template.xlsx`).
- Rewrote `Sf2ExcelExportService` for:
  - Multi-sheet SF2-SHS (REMINDERS + month tabs June–April)
  - Export keeps **REMINDERS + report month only**
  - Calendar Mon–Sat columns (Sunday separators)
  - Marks: blank = present, **X** = absent, **T** = tardy
  - Header: school, ID, division, region, semester, year, grade, section, track/strand
  - Male 14 / female 36 learner slots (template capacity)
  - Signatures + summary enrolment figures
- Form + model fields for SHS header: semester, division, region, track and strand, TVL courses.
- Defaults for ACD (School ID 405431, Davao City, Region XI, etc.) in `config/sf2.php`.
- Docs: `docs/sf2/README.md` updated.

### Migration
- `2026_08_05_000005_add_shs_fields_to_sf2_reports_table`

### Deploy notes
```bash
php artisan migrate --force
php artisan config:clear
php artisan view:clear
```
**Note:** Workbook has no **May** tab; May reports need a MAY sheet added to the template first.

---

## 4. SMS blaster — SIM load expiry notice

**Goal:** Simple reminder when the modem SIM prepaid load is about to run out.

### What was done
- On **SMS Blast**, banner shows SIM load status (OK / warn / expired / not set).
- Admin/staff can **Record load**:
  - Loaded on (date)
  - Valid for (days)
  - Warn this many days before (default 3)
- Faculty can **see** the banner; only admin/staff can edit.
- Stored in `settings` as `sms_sim_load` (JSON) — **no migration**.

### Flow
1. Top up the SIM.  
2. SMS Blast → Record load → save today + days.  
3. Banner turns yellow near expiry, red when expired.  
4. After each top-up, update the record again.

---

## Files / areas touched (high level)

| Area | Key pieces |
|------|------------|
| Kiosks | `GateDevice`, `StudentScanService`, attendance scan/face JS, `kiosk-pairing.js`, logs controller/views/exports, `BackfillAttendanceKioskNames` |
| SF2 | `Sf2ExcelExportService`, `config/sf2.php`, SF2 form, `sf2-template.xlsx`, SF2 migration |
| SIM load | `Setting::smsSimLoadStatus`, `SmsController`, `sms/blast.blade.php`, route `sms.simLoad.update` |

---

## Production checklist (when deploying today’s work)

1. Deploy code (including `public/js/kiosk-pairing.js`, SF2 template, SMS blast view).
2. `composer install --no-dev` if needed.
3. `php artisan migrate --force`
4. `php artisan config:clear` / `view:clear` (then cache if usual).
5. Create & pair kiosks on each gate PC.
6. Optional: backfill kiosk names for old logs.
7. Generate a test SF2 Excel for a month that has a sheet (e.g. August).
8. Record current SIM load on SMS Blast.

---

## Earlier work in the same period (context)

Related features already in the branch / prior sessions (not re-done today as new scope, listed for continuity):

- School calendar (school day / holiday / otherwise)
- Attendance policy (SHS day / evening + temporary override)
- Friday auto-present + SF2 future/Friday defaults
- Faculty multi-class advisories (adviser vs subject teacher)
- SMS blast filters by grade/section
- Student list consecutive late/absent highlights + demo seeder

---

*Documented for internal handover — ACD Online, 5 August 2026.*
