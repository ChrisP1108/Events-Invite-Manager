/**
 * Shared admin bulk-selection behavior for EIM list tables.
 */
(() => {
    'use strict';

    const rowsForGroup = (group, visibleOnly = false) => Array.from(document.querySelectorAll('.eim-bulk-select-row'))
        .filter(row => {
            if (row.dataset.eimBulkGroup !== group) return false;
            if (!visibleOnly) return true;
            const tr = row.closest('tr');
            return !tr || tr.style.display !== 'none';
        });

    const updateAllMasters = () => {
        document.querySelectorAll('.eim-bulk-select-all').forEach(master => {
            updateMaster(master.dataset.eimBulkGroup || '');
        });
    };

    const updateMaster = (group) => {
        const master = Array.from(document.querySelectorAll('.eim-bulk-select-all'))
            .find(input => input.dataset.eimBulkGroup === group);
        if (!master) return;

        const rows = rowsForGroup(group, true);
        const checked = rows.filter(row => row.checked).length;
        master.checked = rows.length > 0 && checked === rows.length;
        master.indeterminate = checked > 0 && checked < rows.length;
    };

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;

        if (target.classList.contains('eim-bulk-select-all')) {
            const group = target.dataset.eimBulkGroup || '';
            rowsForGroup(group, true).forEach(row => { row.checked = target.checked; });
            target.indeterminate = false;
            return;
        }

        if (target.classList.contains('eim-bulk-select-row')) {
            updateMaster(target.dataset.eimBulkGroup || '');
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-eim-bulk-form]')) return;

        const actionField = form.querySelector('select[name="bulk_action"]')
            || document.querySelector(`select[form="${form.id}"][name="bulk_action"]`);
        const action = actionField?.value || '';
        const selected = document.querySelectorAll(`input[form="${form.id}"][name="bulk_ids[]"]:checked`);

        if (!action) {
            event.preventDefault();
            window.alert('Choose a bulk action before applying.');
            return;
        }

        if (selected.length === 0) {
            event.preventDefault();
            window.alert('Select at least one item before applying a bulk action.');
            return;
        }

        if (action === 'delete' && !window.confirm(`Delete ${selected.length} selected item${selected.length === 1 ? '' : 's'}?`)) {
            event.preventDefault();
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('tbody').forEach(tbody => {
            new MutationObserver(updateAllMasters).observe(tbody, { childList: true });
        });
        updateAllMasters();
    });
})();
