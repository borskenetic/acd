/**
 * SF2 per-learner month calendar: click weekdays to mark absent, tardy, or half-day.
 */
(function () {
  const DOW = ['Su', 'M', 'T', 'W', 'Th', 'F', 'Sa'];

  function getReportMonthYear() {
    const monthEl = document.querySelector('[name="report_month"]');
    const yearEl = document.querySelector('[name="report_year"]');
    const month = monthEl ? parseInt(monthEl.value, 10) : NaN;
    const year = yearEl ? parseInt(yearEl.value, 10) : NaN;
    if (!month || !year) {
      return null;
    }
    return { month, year };
  }

  function parseDateList(raw) {
    if (!raw) {
      return [];
    }
    if (Array.isArray(raw)) {
      return raw.filter(Boolean);
    }
    const s = String(raw).trim();
    if (s.startsWith('[')) {
      try {
        const parsed = JSON.parse(s);
        if (Array.isArray(parsed)) {
          return parsed.filter((d) => /^\d{4}-\d{2}-\d{2}$/.test(d));
        }
      } catch (e) {
        /* fall through */
      }
    }
    return s
      .split(/[\s,;]+/)
      .map((part) => part.trim())
      .filter((part) => /^\d{4}-\d{2}-\d{2}$/.test(part));
  }

  function formatDate(y, m, d) {
    return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  }

  function isWeekday(year, month, day) {
    const dt = new Date(year, month - 1, day);
    const dow = dt.getDay();
    return dow >= 1 && dow <= 5;
  }

  function syncHiddenInputs(calRoot) {
    const absentInput = calRoot.querySelector('.sf2-absent-input');
    const tardyInput = calRoot.querySelector('.sf2-tardy-input');
    const halfInput = calRoot.querySelector('.sf2-half-input');
    const absent = JSON.parse(calRoot.dataset.absent || '[]');
    const tardy = JSON.parse(calRoot.dataset.tardy || '[]');
    const half = JSON.parse(calRoot.dataset.half || '[]');
    if (absentInput) {
      absentInput.value = absent.join('\n');
    }
    if (tardyInput) {
      tardyInput.value = tardy.join('\n');
    }
    if (halfInput) {
      halfInput.value = half.join('\n');
    }
  }

  function renderGrid(calRoot) {
    const grid = calRoot.querySelector('.sf2-cal-grid');
    if (!grid) {
      return;
    }

    const my = getReportMonthYear();
    if (!my) {
      grid.innerHTML = '<p class="small text-warning mb-0">Select report month and year above first.</p>';
      return;
    }

    const { month, year } = my;
    const absent = new Set(JSON.parse(calRoot.dataset.absent || '[]'));
    const tardy = new Set(JSON.parse(calRoot.dataset.tardy || '[]'));
    const half = new Set(JSON.parse(calRoot.dataset.half || '[]'));
    const mode = calRoot.dataset.mode || 'absent';

    const first = new Date(year, month - 1, 1);
    const daysInMonth = new Date(year, month, 0).getDate();
    const startPad = first.getDay();

    let html = '';
    DOW.forEach((label) => {
      html += `<div class="sf2-cal-dow">${label}</div>`;
    });

    for (let i = 0; i < startPad; i++) {
      html += '<div class="sf2-cal-day is-outside-month" aria-hidden="true"></div>';
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const dateStr = formatDate(year, month, day);
      const weekday = isWeekday(year, month, day);
      let cls = 'sf2-cal-day';
      if (!weekday) {
        cls += ' is-weekend';
      } else if (absent.has(dateStr)) {
        cls += ' is-absent';
      } else if (half.has(dateStr)) {
        cls += ' is-half';
      } else if (tardy.has(dateStr)) {
        cls += ' is-tardy';
      }

      const disabled = weekday ? '' : ' disabled';
      html += `<button type="button" class="${cls}" data-date="${dateStr}"${disabled}>${day}</button>`;
    }

    const totalCells = startPad + daysInMonth;
    const trailing = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
    for (let i = 0; i < trailing; i++) {
      html += '<div class="sf2-cal-day is-outside-month" aria-hidden="true"></div>';
    }

    grid.innerHTML = html;

    const label = calRoot.querySelector('.sf2-cal-month-label');
    if (label) {
      const monthNames = [
        '', 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
      ];
      label.textContent = `${monthNames[month]} ${year}`;
    }

    calRoot.dataset.mode = mode;
  }

  function toggleDay(calRoot, dateStr) {
    const absent = new Set(JSON.parse(calRoot.dataset.absent || '[]'));
    const tardy = new Set(JSON.parse(calRoot.dataset.tardy || '[]'));
    const half = new Set(JSON.parse(calRoot.dataset.half || '[]'));
    const mode = calRoot.dataset.mode || 'absent';

    if (mode === 'absent') {
      if (absent.has(dateStr)) {
        absent.delete(dateStr);
      } else {
        absent.add(dateStr);
        tardy.delete(dateStr);
        half.delete(dateStr);
      }
    } else if (mode === 'half') {
      if (half.has(dateStr)) {
        half.delete(dateStr);
      } else {
        half.add(dateStr);
        absent.delete(dateStr);
        tardy.delete(dateStr);
      }
    } else {
      if (tardy.has(dateStr)) {
        tardy.delete(dateStr);
      } else {
        tardy.add(dateStr);
        absent.delete(dateStr);
        half.delete(dateStr);
      }
    }

    calRoot.dataset.absent = JSON.stringify([...absent].sort());
    calRoot.dataset.tardy = JSON.stringify([...tardy].sort());
    calRoot.dataset.half = JSON.stringify([...half].sort());
    syncHiddenInputs(calRoot);
    renderGrid(calRoot);
  }

  function mount(studentRow) {
    const calRoot = studentRow.querySelector('.sf2-attendance-cal');
    if (!calRoot || calRoot.dataset.mounted === '1') {
      if (calRoot && calRoot.dataset.mounted === '1') {
        renderGrid(calRoot);
      }
      return;
    }

    calRoot.dataset.mounted = '1';
    calRoot.dataset.mode = calRoot.dataset.mode || 'absent';

    const absentInit = parseDateList(calRoot.dataset.absentInitial);
    const tardyInit = parseDateList(calRoot.dataset.tardyInitial);
    const halfInit = parseDateList(calRoot.dataset.halfInitial);
    calRoot.dataset.absent = JSON.stringify(absentInit);
    calRoot.dataset.tardy = JSON.stringify(tardyInit);
    calRoot.dataset.half = JSON.stringify(halfInit);
    syncHiddenInputs(calRoot);

    calRoot.querySelectorAll('.sf2-cal-mode').forEach((btn) => {
      btn.addEventListener('click', () => {
        calRoot.dataset.mode = btn.dataset.mode;
        calRoot.querySelectorAll('.sf2-cal-mode').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
      });
    });

    calRoot.querySelector('.sf2-cal-clear')?.addEventListener('click', () => {
      calRoot.dataset.absent = '[]';
      calRoot.dataset.tardy = '[]';
      calRoot.dataset.half = '[]';
      syncHiddenInputs(calRoot);
      renderGrid(calRoot);
    });

    calRoot.querySelector('.sf2-cal-grid')?.addEventListener('click', (e) => {
      const btn = e.target.closest('.sf2-cal-day[data-date]');
      if (!btn || btn.disabled) {
        return;
      }
      toggleDay(calRoot, btn.dataset.date);
    });

    renderGrid(calRoot);
  }

  function refreshAll() {
    document.querySelectorAll('.sf2-student-row').forEach((row) => {
      const cal = row.querySelector('.sf2-attendance-cal');
      if (cal) {
        renderGrid(cal);
      }
    });
  }

  function initAll() {
    document.querySelectorAll('.sf2-student-row').forEach((row) => mount(row));
  }

  document.addEventListener('DOMContentLoaded', () => {
    initAll();

    document.querySelector('[name="report_month"]')?.addEventListener('change', refreshAll);
    document.querySelector('[name="report_year"]')?.addEventListener('change', refreshAll);
    document.querySelector('[name="report_year"]')?.addEventListener('input', refreshAll);
  });

  window.Sf2AttendanceCalendar = { mount, refreshAll, initAll };
})();
