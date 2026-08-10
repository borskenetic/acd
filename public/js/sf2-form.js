(function () {
  const container = document.getElementById('sf2-students-container');
  const template = document.getElementById('sf2-student-row-template');
  const countInput = document.getElementById('sf2-student-count');
  const generateBtn = document.getElementById('sf2-generate-rows');
  const gradeSelect = document.getElementById('sf2-grade-select');
  const sectionSelect = document.getElementById('sf2-section-select');
  const sectionManual = document.getElementById('sf2-section-manual');
  const loadLogsBtn = document.getElementById('sf2-load-from-logs');
  const sectionsByGrade = window.SF2_SECTIONS_BY_GRADE || {};

  function selectedSection() {
    if (sectionManual && !sectionManual.classList.contains('d-none') && sectionManual.name === 'section') {
      return (sectionManual.value || '').trim();
    }
    if (sectionSelect && sectionSelect.name === 'section') {
      return (sectionSelect.value || '').trim();
    }
    const named = document.querySelector('[name="section"]');
    return named ? String(named.value || '').trim() : '';
  }

  function populateSectionOptions(grade, selected) {
    if (!sectionSelect) {
      return;
    }

    const sections = (grade && sectionsByGrade[grade]) ? sectionsByGrade[grade] : [];
    const current = selected || sectionSelect.value;

    sectionSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    if (!grade) {
      placeholder.textContent = '— Select grade first —';
    } else if (sections.length) {
      placeholder.textContent = '— Select section —';
    } else {
      placeholder.textContent = '— No sections for this grade —';
    }
    sectionSelect.appendChild(placeholder);

    let found = false;
    sections.forEach((name) => {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      if (current && current === name) {
        opt.selected = true;
        found = true;
      }
      sectionSelect.appendChild(opt);
    });

    if (current && !found) {
      const extra = document.createElement('option');
      extra.value = current;
      extra.textContent = current + ' (current)';
      extra.selected = true;
      sectionSelect.appendChild(extra);
    }
  }

  // Section dropdown (skip if inline binder already ran on the select).
  if (gradeSelect && sectionSelect && !gradeSelect.dataset.sf2SectionBound) {
    gradeSelect.addEventListener('change', function () {
      populateSectionOptions(this.value, '');
    });
    populateSectionOptions(gradeSelect.value, sectionSelect.dataset.initial || sectionSelect.value);
  }

  function mountCalendar(row) {
    if (window.Sf2AttendanceCalendar) {
      window.Sf2AttendanceCalendar.mount(row);
    }
  }

  let rowIndex = container
    ? container.querySelectorAll('.sf2-student-row').length
    : 0;

  function addRow(data) {
    if (!container || !template) {
      console.warn('SF2: learner row template missing');
      return;
    }

    const clone = template.content.cloneNode(true);
    const row = clone.querySelector('.sf2-student-row');
    row.dataset.index = String(rowIndex);

    const prefix = `students[${rowIndex}]`;
    row.querySelectorAll('[data-field]').forEach((el) => {
      const field = el.getAttribute('data-field');
      el.name = `${prefix}[${field}]`;
      if (data && data[field] !== undefined && data[field] !== null) {
        el.value = data[field];
      }
    });

    const sexSelect = row.querySelector('select[name$="[sex]"]');
    if (sexSelect && data && data.sex) {
      sexSelect.value = data.sex;
    }

    const cal = row.querySelector('.sf2-attendance-cal');
    if (cal && data) {
      if (data.absent_dates) {
        const absent = Array.isArray(data.absent_dates)
          ? data.absent_dates
          : String(data.absent_dates).split(/[\s,;]+/).filter(Boolean);
        cal.dataset.absentInitial = JSON.stringify(absent);
      }
      if (data.tardy_dates) {
        const tardy = Array.isArray(data.tardy_dates)
          ? data.tardy_dates
          : String(data.tardy_dates).split(/[\s,;]+/).filter(Boolean);
        cal.dataset.tardyInitial = JSON.stringify(tardy);
      }
      if (data.half_day_dates) {
        const half = Array.isArray(data.half_day_dates)
          ? data.half_day_dates
          : String(data.half_day_dates).split(/[\s,;]+/).filter(Boolean);
        cal.dataset.halfInitial = JSON.stringify(half);
      }
    }

    row.querySelector('.sf2-row-number').textContent = String(rowIndex + 1);
    container.appendChild(clone);
    mountCalendar(row);
    rowIndex++;
  }

  function replaceAllStudents(rows) {
    if (!container) {
      return;
    }
    container.innerHTML = '';
    rowIndex = 0;

    if (!rows || rows.length === 0) {
      addRow({ sex: 'male' });
      return;
    }

    rows.forEach((row) => addRow(row));
  }

  async function loadFromLogs() {
    const grade = (gradeSelect?.value || document.querySelector('[name="grade_level"]')?.value || '').trim();
    const section = selectedSection();
    const month = document.querySelector('[name="report_month"]')?.value;
    const year = document.querySelector('[name="report_year"]')?.value;

    if (!grade || !section || !month || !year) {
      alert('Select grade level, section, report month, and year first.');
      return;
    }

    if (!window.SF2_PREVIEW_URL) {
      alert('SF2 preview URL is missing. Refresh the page and try again.');
      return;
    }

    const url = new URL(window.SF2_PREVIEW_URL, window.location.origin);
    url.searchParams.set('grade_level', grade);
    url.searchParams.set('section', section);
    url.searchParams.set('report_month', month);
    url.searchParams.set('report_year', year);

    if (loadLogsBtn) {
      loadLogsBtn.disabled = true;
      loadLogsBtn.textContent = 'Loading…';
    }

    try {
      const response = await fetch(url.toString(), {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });

      const contentType = response.headers.get('content-type') || '';
      if (!response.ok) {
        let detail = 'HTTP ' + response.status;
        try {
          if (contentType.includes('application/json')) {
            const errBody = await response.json();
            detail = errBody.message || JSON.stringify(errBody);
          }
        } catch (e) { /* ignore */ }
        throw new Error('Could not load attendance data (' + detail + ').');
      }

      if (!contentType.includes('application/json')) {
        throw new Error('Server did not return JSON (login expired?). Refresh and sign in again.');
      }

      const data = await response.json();

      if (data.warnings && data.warnings.length) {
        alert(data.warnings.join('\n'));
      }

      if (!data.students || data.students.length === 0) {
        alert('No learners loaded. Check that students have grade, section, and sex (male/female) set.');
        return;
      }

      replaceAllStudents(data.students);
    } catch (err) {
      console.error('SF2 load from logs failed', err);
      alert(err.message || 'Failed to load from attendance logs.');
    } finally {
      if (loadLogsBtn) {
        loadLogsBtn.disabled = false;
        loadLogsBtn.textContent = 'Load from attendance logs';
      }
    }
  }

  // Click wiring is done on create.blade (single handler). Only expose the API here.
  // Bind as backup if the page did not already claim the button.
  if (loadLogsBtn && loadLogsBtn.dataset.sf2Bound !== '1' && loadLogsBtn.dataset.sf2InlineBound !== '1') {
    loadLogsBtn.dataset.sf2Bound = '1';
    loadLogsBtn.addEventListener('click', function (e) {
      e.preventDefault();
      loadFromLogs();
    });
  }

  if (generateBtn && countInput && !generateBtn.dataset.sf2Bound) {
    generateBtn.dataset.sf2Bound = '1';
    generateBtn.addEventListener('click', function () {
      const n = parseInt(countInput.value, 10);
      if (!n || n < 1 || n > 80) {
        alert('Enter number of students (1–80).');
        return;
      }
      const existing = container
        ? container.querySelectorAll('.sf2-student-row').length
        : 0;
      const toAdd = Math.max(0, n - existing);
      for (let i = 0; i < toAdd; i++) {
        addRow({ sex: 'male' });
      }
    });
  }

  const addBtn = document.getElementById('sf2-add-student');
  if (addBtn && !addBtn.dataset.sf2Bound) {
    addBtn.dataset.sf2Bound = '1';
    addBtn.addEventListener('click', function () {
      addRow({ sex: 'male' });
    });
  }

  if (container && !container.dataset.sf2Bound) {
    container.dataset.sf2Bound = '1';
    container.addEventListener('click', function (e) {
      const btn = e.target.closest('.sf2-remove-row');
      if (!btn) {
        return;
      }
      const row = btn.closest('.sf2-student-row');
      if (row && container.querySelectorAll('.sf2-student-row').length > 1) {
        row.remove();
      }
    });
  }

  window.Sf2Form = {
    addRow,
    replaceAllStudents,
    populateSectionOptions,
    loadFromLogs,
    selectedSection,
  };
})();
