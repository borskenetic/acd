<script>
(function () {
    const sectionsByGrade = @json($sectionsByGrade);
    const accessLevels = @json($accessLevelOptions);
    const initialRows = @json($initialRows);
    const roleSelect = document.getElementById('roleSelect');
    const advisoryBox = document.querySelector('.faculty-advisory-fields');
    const rowsEl = document.getElementById('advisoryRows');
    const addBtn = document.getElementById('addAdvisoryRow');
    let rowIndex = 0;

    function toggleAdvisory() {
        const isFaculty = roleSelect.value === 'faculty';
        if (advisoryBox) advisoryBox.style.display = isFaculty ? '' : 'none';
    }

    function sectionOptionsHtml(grade, selected) {
        const list = sectionsByGrade[grade] || [];
        let html = '<option value="">Section</option>';
        list.forEach(function (sec) {
            html += '<option value="' + sec.replace(/"/g, '&quot;') + '"'
                + (sec === selected ? ' selected' : '') + '>' + sec + '</option>';
        });
        if (selected && list.indexOf(selected) === -1) {
            html += '<option value="' + String(selected).replace(/"/g, '&quot;') + '" selected>'
                + selected + ' (current)</option>';
        }
        return html;
    }

    function accessOptionsHtml(selected) {
        let html = '';
        Object.keys(accessLevels).forEach(function (key) {
            html += '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>'
                + accessLevels[key] + '</option>';
        });
        return html;
    }

    function addRow(data) {
        data = data || { year: '', section: '', access_level: 'adviser' };
        const i = rowIndex++;
        const wrap = document.createElement('div');
        wrap.className = 'row g-2 align-items-end advisory-row';
        wrap.innerHTML =
            '<div class="col-md-4">' +
                '<label class="form-label small mb-0">Grade</label>' +
                '<select name="advisories[' + i + '][year]" class="form-select form-select-sm year-select"></select>' +
            '</div>' +
            '<div class="col-md-3">' +
                '<label class="form-label small mb-0">Section</label>' +
                '<select name="advisories[' + i + '][section]" class="form-select form-select-sm section-select"></select>' +
            '</div>' +
            '<div class="col-md-4">' +
                '<label class="form-label small mb-0">Access</label>' +
                '<select name="advisories[' + i + '][access_level]" class="form-select form-select-sm">' +
                    accessOptionsHtml(data.access_level || 'adviser') +
                '</select>' +
            '</div>' +
            '<div class="col-md-1">' +
                '<button type="button" class="btn btn-sm btn-outline-danger w-100 remove-row" title="Remove">&times;</button>' +
            '</div>';

        const yearSelect = wrap.querySelector('.year-select');
        const yearOptions = @json($yearOptions);
        yearSelect.innerHTML = '<option value="">Grade</option>';
        yearOptions.forEach(function (y) {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            if (y === data.year) opt.selected = true;
            yearSelect.appendChild(opt);
        });

        const sectionSelect = wrap.querySelector('.section-select');
        sectionSelect.innerHTML = sectionOptionsHtml(data.year || '', data.section || '');

        yearSelect.addEventListener('change', function () {
            sectionSelect.innerHTML = sectionOptionsHtml(yearSelect.value, '');
        });

        wrap.querySelector('.remove-row').addEventListener('click', function () {
            if (rowsEl.querySelectorAll('.advisory-row').length <= 1) {
                yearSelect.value = '';
                sectionSelect.innerHTML = sectionOptionsHtml('', '');
                return;
            }
            wrap.remove();
        });

        rowsEl.appendChild(wrap);
    }

    roleSelect.addEventListener('change', toggleAdvisory);
    addBtn?.addEventListener('click', function () { addRow(); });

    (initialRows && initialRows.length ? initialRows : [{ year: '', section: '', access_level: 'adviser' }])
        .forEach(function (row) { addRow(row); });

    toggleAdvisory();
})();
</script>
