/* global ajaxurl, eimBudgetAdmin */

/**
 * Admin budget page interactions.
 *
 * Handles three independent features driven by eimBudgetAdmin config:
 *   - BudgetPlansTable  — AJAX search + sort on the plans list view
 *   - LineItemsTable    — AJAX search + sort on the plan detail view
 *   - CategoryTable     — client-side sort on the category summary table
 */
(() => {
    'use strict';

    const config = window.eimBudgetAdmin ?? {};

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }
        return url;
    };

    const debounce = (fn, delay = 250) => {
        let timer = 0;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    // =========================================================================
    // BudgetPlansTable — AJAX search + sort for the plans list
    // =========================================================================

    class BudgetPlansTable {
        #table;
        #tbody;
        #search;
        #field;
        #count;
        #spinner;
        #sort;
        #order;

        constructor() {
            this.#table   = document.getElementById('eim-budget-plans-table');
            this.#tbody   = document.getElementById('eim-budget-plans-table-body');
            this.#search  = document.getElementById('eim-budget-plan-search');
            this.#field   = document.getElementById('eim-budget-plan-search-field');
            this.#count   = document.getElementById('eim-budget-plan-count');
            this.#spinner = document.getElementById('eim-budget-plan-loading');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort  = this.#table.dataset.sort  || 'name';
            this.#order = this.#table.dataset.order || 'asc';

            this.#search.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#refresh();
                });
            }
        }

        async #refresh() {
            this.#spinner?.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_budget_plans', {
                    nonce: config.searchNonce,
                    query: this.#search?.value || '',
                    sort:  this.#sort,
                    order: this.#order,
                    field: this.#field?.value || '',
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#updateSortLinks();
            } catch (err) {
                console.error('[EIM] Budget plan search failed:', err);
            } finally {
                this.#spinner?.classList.remove('is-active');
            }
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // =========================================================================
    // LineItemsTable — AJAX search + sort for line items on plan detail view
    // =========================================================================

    class LineItemsTable {
        #table;
        #tbody;
        #search;
        #field;
        #count;
        #spinner;
        #sort;
        #order;
        #planId;

        constructor() {
            this.#table   = document.getElementById('eim-line-items-table');
            this.#tbody   = document.getElementById('eim-line-items-table-body');
            this.#search  = document.getElementById('eim-line-item-search');
            this.#field   = document.getElementById('eim-line-item-search-field');
            this.#count   = document.getElementById('eim-line-item-count');
            this.#spinner = document.getElementById('eim-line-item-loading');

            if (!this.#table || !this.#tbody || !config.lineItemNonce) return;

            this.#planId = Number(this.#table.dataset.planId || config.planId || 0);
            this.#sort   = this.#table.dataset.sort  || config.lineItems?.sort  || 'sort_order';
            this.#order  = this.#table.dataset.order || config.lineItems?.order || 'asc';

            this.#search?.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'sort_order';
                    this.#order = link.dataset.order || 'asc';
                    this.#refresh();
                });
            }
        }

        async #refresh() {
            this.#spinner?.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_budget_line_items', {
                    nonce:   config.lineItemNonce,
                    plan_id: this.#planId,
                    query:   this.#search?.value || '',
                    sort:    this.#sort,
                    order:   this.#order,
                    field:   this.#field?.value || '',
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#updateSortLinks();
            } catch (err) {
                console.error('[EIM] Line item search failed:', err);
            } finally {
                this.#spinner?.classList.remove('is-active');
            }
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // =========================================================================
    // CategoryTable — client-side sort for the category summary table
    // =========================================================================

    class CategoryTable {
        #table;
        #tbody;
        #sort;
        #order;

        constructor() {
            this.#table = document.getElementById('eim-budget-category-table');
            this.#tbody = document.getElementById('eim-budget-category-tbody');

            if (!this.#table || !this.#tbody) return;

            this.#sort  = this.#table.dataset.sort  || 'category';
            this.#order = this.#table.dataset.order || 'asc';

            for (const link of this.#table.querySelectorAll('.eim-cat-sort')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'category';
                    this.#order = link.dataset.order || 'asc';
                    this.#sortRows();
                    this.#updateLinks();
                });
            }
        }

        #sortRows() {
            const rows = [...this.#tbody.querySelectorAll('tr')];
            const colIndex = { category: 0, estimated: 1, paid: 2 }[this.#sort] ?? 0;
            const isNumeric = this.#sort !== 'category';
            const mul = this.#order === 'desc' ? -1 : 1;

            rows.sort((a, b) => {
                const aVal = a.querySelectorAll('td')[colIndex]?.dataset.val ?? '';
                const bVal = b.querySelectorAll('td')[colIndex]?.dataset.val ?? '';
                if (isNumeric) return mul * (Number(aVal) - Number(bVal));
                return mul * aVal.localeCompare(bVal);
            });

            for (const row of rows) this.#tbody.appendChild(row);
        }

        #updateLinks() {
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;

            for (const link of this.#table.querySelectorAll('.eim-cat-sort')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // =========================================================================
    // LineItemEditForm — pre-fill add form when Edit is clicked on a row
    // =========================================================================

    class LineItemEditForm {
        #formWrapper;
        #formTitle;
        #form;
        #submitBtn;
        #cancelWrap;
        #itemIdInput;
        #tbody;

        constructor() {
            this.#formWrapper = document.getElementById('eim-budget-line-item-form');
            this.#formTitle   = document.getElementById('eim-li-form-title');
            this.#form        = this.#formWrapper?.querySelector('form');
            this.#submitBtn   = document.getElementById('eim-li-submit');
            this.#cancelWrap  = document.getElementById('eim-li-cancel-wrap');
            this.#itemIdInput = this.#form?.querySelector('[name="line_item_id"]');
            this.#tbody       = document.getElementById('eim-line-items-table-body');

            if (!this.#form || !this.#tbody) return;

            // Use event delegation so it works after AJAX refreshes the tbody.
            this.#tbody.addEventListener('click', (e) => {
                const link = e.target.closest('.eim-edit-line-item');
                if (!link) return;
                e.preventDefault();
                this.#populate(link.dataset);
                this.#formWrapper?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            document.getElementById('eim-li-cancel')?.addEventListener('click', (e) => {
                e.preventDefault();
                this.#reset();
            });
        }

        #populate(d) {
            this.#setField('line_item_id', d.id || '0');
            this.#setField('label',        d.label || '');
            this.#setField('category',     d.category || 'other');
            this.#setField('event_id',     d.eventId || '0');
            this.#setField('unit_cost',    d.unitCost || '');
            this.#setField('paid_amount',  d.paid || '0.00');
            this.#setField('vendor_name',  d.vendor || '');
            this.#setField('notes',        d.notes || '');

            const qtyMode = this.#form.querySelector('[name="quantity_mode"]');
            if (qtyMode) {
                qtyMode.value = d.quantityMode || 'fixed';
                qtyMode.dispatchEvent(new Event('change'));
            }
            this.#setField('quantity', d.quantity || '1');

            if (this.#formTitle)  this.#formTitle.textContent = 'Edit Line Item';
            if (this.#submitBtn)  this.#submitBtn.value = 'Update Line Item';
            if (this.#cancelWrap) this.#cancelWrap.style.display = '';
        }

        #reset() {
            this.#setField('line_item_id', '0');
            this.#setField('label',        '');
            this.#setField('event_id',     '0');
            this.#setField('unit_cost',    '');
            this.#setField('paid_amount',  '0.00');
            this.#setField('vendor_name',  '');
            this.#setField('notes',        '');
            this.#setField('quantity',     '1');

            const catSelect = this.#form.querySelector('[name="category"]');
            if (catSelect) catSelect.selectedIndex = 0;

            const qtyMode = this.#form.querySelector('[name="quantity_mode"]');
            if (qtyMode) {
                qtyMode.value = 'fixed';
                qtyMode.dispatchEvent(new Event('change'));
            }

            if (this.#formTitle)  this.#formTitle.textContent = 'Add Line Item';
            if (this.#submitBtn)  this.#submitBtn.value = 'Add Line Item';
            if (this.#cancelWrap) this.#cancelWrap.style.display = 'none';
        }

        #setField(name, value) {
            const el = this.#form?.querySelector(`[name="${name}"]`);
            if (el) el.value = value;
        }
    }

    // =========================================================================
    // Bootstrap
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled)     new BudgetPlansTable();
        if (config.lineItems?.enabled) new LineItemsTable();
        new CategoryTable();     // always attempt — renders nothing if table absent
        new LineItemEditForm();  // always attempt — renders nothing if form absent
    });
})();
