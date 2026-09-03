/**
 * Panel estado alumnos (secretaría): tablas ordenables por columna.
 */

import {
    initPageshowSubmitRestore,
    initSelectSubmitLoading,
    initSubmitLoading,
} from '../ui/submitLoading.js';

function estadoAlumnoGetCellSortValue(cell) {
    if (!cell) {
        return '';
    }
    if (cell.dataset.sortValue !== undefined && cell.dataset.sortValue !== '') {
        return cell.dataset.sortValue;
    }
    return (cell.textContent || '').replace(/\s+/g, ' ').trim();
}

function estadoAlumnoCompareValues(aVal, bVal, sortType, ascending) {
    const emptyLast = function (val) {
        return val === '' || val === '—' || val === '-';
    };

    if (emptyLast(aVal) && emptyLast(bVal)) {
        return 0;
    }
    if (emptyLast(aVal)) {
        return 1;
    }
    if (emptyLast(bVal)) {
        return -1;
    }

    let result = 0;

    if (sortType === 'number') {
        const aNum = parseFloat(String(aVal).replace(/[^\d.,-]/g, '').replace(',', '.')) || 0;
        const bNum = parseFloat(String(bVal).replace(/[^\d.,-]/g, '').replace(',', '.')) || 0;
        result = aNum - bNum;
    } else {
        result = String(aVal).localeCompare(String(bVal), 'es', {
            sensitivity: 'base',
            numeric: true,
        });
    }

    return ascending ? result : -result;
}

function estadoAlumnoRenumberRows(tbody) {
    tbody.querySelectorAll('tr').forEach(function (row, index) {
        const numCell = row.querySelector('.col-numero');
        if (numCell) {
            numCell.textContent = String(index + 1);
        }
    });
}

function estadoAlumnoInitSortableTable(table) {
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    if (!thead || !tbody) {
        return;
    }

    const headers = Array.from(thead.querySelectorAll('th'));

    headers.forEach(function (th, colIndex) {
        if (
            th.classList.contains('col-numero')
            || th.classList.contains('col-accion')
            || th.dataset.sortable === 'false'
        ) {
            return;
        }

        th.classList.add('th-sortable');
        th.setAttribute('role', 'button');
        th.setAttribute('tabindex', '0');
        th.setAttribute('aria-sort', 'none');

        const icon = document.createElement('span');
        icon.className = 'th-sort-icon';
        icon.setAttribute('aria-hidden', 'true');
        th.appendChild(document.createTextNode(' '));
        th.appendChild(icon);

        const sortTable = function () {
            const current = th.getAttribute('aria-sort');
            const ascending = current !== 'ascending';
            const sortType = th.dataset.sortType || 'text';

            headers.forEach(function (header) {
                if (header.classList.contains('th-sortable')) {
                    header.setAttribute('aria-sort', 'none');
                }
            });
            th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort(function (rowA, rowB) {
                const aVal = estadoAlumnoGetCellSortValue(rowA.children[colIndex]);
                const bVal = estadoAlumnoGetCellSortValue(rowB.children[colIndex]);
                return estadoAlumnoCompareValues(aVal, bVal, sortType, ascending);
            });

            rows.forEach(function (row) {
                tbody.appendChild(row);
            });

            estadoAlumnoRenumberRows(tbody);
        };

        th.addEventListener('click', sortTable);
        th.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                sortTable();
            }
        });
    });
}

function initEstadoAlumnoConsultaLoading() {
    initPageshowSubmitRestore();

    initSubmitLoading(document.getElementById('cuentasForm'), {
        validate: () => {
            const mes = document.getElementById('mes');
            if (mes && mes.value === '') {
                mes.focus();
                return false;
            }
            return true;
        },
    });

    initSubmitLoading(document.getElementById('listadoFamiliasForm'), {
        validate: () => {
            const mes = document.getElementById('mes_familias');
            if (mes && mes.value === '') {
                mes.focus();
                return false;
            }
            return true;
        },
    });

    ['emailsForm', 'bolsaForm', 'cursoForm', 'cursoFormRevision'].forEach((formId) => {
        initSubmitLoading(document.getElementById(formId));
    });

    initSelectSubmitLoading(
        document.getElementById('cursoForm'),
        document.getElementById('curso'),
        { shouldSubmit: () => document.getElementById('curso')?.value !== '' },
    );

    initSelectSubmitLoading(
        document.getElementById('cursoFormRevision'),
        document.getElementById('curso_revision'),
        { shouldSubmit: () => document.getElementById('curso_revision')?.value !== '' },
    );

    initSelectSubmitLoading(
        document.getElementById('bolsaForm'),
        document.getElementById('area_bolsa'),
    );

    initSelectSubmitLoading(
        document.getElementById('emailsForm'),
        document.getElementById('curso_emails'),
    );
}

export function initEstadoAlumnoPage() {
    document.querySelectorAll('.js-sortable-table').forEach(estadoAlumnoInitSortableTable);
    initEstadoAlumnoConsultaLoading();
}
