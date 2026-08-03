(function () {
  const container = document.getElementById('sf2-students-container');
  const template = document.getElementById('sf2-student-row-template');
  const countInput = document.getElementById('sf2-student-count');
  const generateBtn = document.getElementById('sf2-generate-rows');
  const gradeSelect = document.getElementById('sf2-grade-select');
  const sectionSelect = document.getElementById('sf2-section-select');
  const loadLogsBtn = document.getElementById('sf2-load-from-logs');
  const sectionsByGrade = window.SF2_SECTIONS_BY_GRADE || {};

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

  // Section dropdown must work even if the learner grid markup is missing.
  if (gradeSelect && sectionSelect) {
    gradeSelect.addEventListener('change', function () {
      populateSectionOptions(this.value, '');
    });

    populateSectionOptions(gradeSelect.value, sectionSelect.dataset.initial || sectionSelect.value);
  }

  if (!container || !template) {
    return;
  }

  let rowIndex = container.querySelectorAll('.sf2-student-row').length;

  function mountCalendar(row) {
    if (window.Sf2AttendanceCalendar) {
      window.Sf2AttendanceCalendar.mount(row);
    }
  }

  function addRow(data) {
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
    }

    row.querySelector('.sf2-row-number').textContent = String(rowIndex + 1);
    container.appendChild(clone);
    mountCalendar(row);
    rowIndex++;
  }

  function replaceAllStudents(rows) {
    container.innerHTML = '';
    rowIndex = 0;

    if (!rows || rows.length === 0) {
      addRow({ sex: 'male' });
      return;
    }

    rows.forEach((row) => addRow(row));
  }

  if (loadLogsBtn) {
    loadLogsBtn.addEventListener('click', async function () {
      const grade = gradeSelect?.value || document.querySelector('[name="grade_level"]')?.value;
      const section = sectionSelect?.value || document.querySelector('[name="section"]')?.value;
      const month = document.querySelector('[name="report_month"]')?.value;
      const year = document.querySelector('[name="report_year"]')?.value;

      if (!grade || !section || !month || !year) {
        alert('Select grade level, section, report month, and year first.');
        return;
      }

      const url = new URL(window.SF2_PREVIEW_URL, window.location.origin);
      url.searchParams.set('grade_level', grade);
      url.searchParams.set('section', section);
      url.searchParams.set('report_month', month);
      url.searchParams.set('report_year', year);

      loadLogsBtn.disabled = true;
      loadLogsBtn.textContent = 'Loading…';

      try {
        const response = await fetch(url.toString(), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });

        if (!response.ok) {
          throw new Error('Could not load attendance data.');
        }

        const data = await response.json();

        if (data.warnings && data.warnings.length) {
          alert(data.warnings.join('\n'));
        }

        if (!data.students || data.students.length === 0) {
          alert('No learners loaded. Check that students have grade, section, and sex set.');
          return;
        }

        replaceAllStudents(data.students);
      } catch (err) {
        alert(err.message || 'Failed to load from attendance logs.');
      } finally {
        loadLogsBtn.disabled = false;
        loadLogsBtn.textContent = 'Load from attendance logs';
      }
    });
  }

  if (generateBtn && countInput) {
    generateBtn.addEventListener('click', function () {
      const n = parseInt(countInput.value, 10);
      if (!n || n < 1 || n > 80) {
        alert('Enter number of students (1–80).');
        return;
      }
      const existing = container.querySelectorAll('.sf2-student-row').length;
      const toAdd = Math.max(0, n - existing);
      for (let i = 0; i < toAdd; i++) {
        addRow({ sex: 'male' });
      }
    });
  }

  document.getElementById('sf2-add-student')?.addEventListener('click', function () {
    addRow({ sex: 'male' });
  });

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

  window.Sf2Form = {
    addRow,
    replaceAllStudents,
    populateSectionOptions,
  };
})();
