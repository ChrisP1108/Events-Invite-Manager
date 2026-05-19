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

    /**
     * Build an absolute WordPress AJAX URL for the given action and params.
     *
     * @param {string}                       action The wp_ajax_* action name.
     * @param {Record<string,string|number>} params Additional query-string parameters.
     * @returns {URL} The fully constructed URL object.
     */
    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }
        return url;
    };

    /**
     * Returns a debounced version of the given function that delays invocation
     * until after `delay` milliseconds have elapsed since the last call.
     *
     * @param {Function} fn    The function to debounce.
     * @param {number}   delay Milliseconds to wait before invoking (default 250).
     * @returns {Function} A new debounced function.
     */
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

    /**
     * Manages the AJAX search and sort behaviour for the budget plans list table.
     */
    class BudgetPlansTable {
        /** @type {HTMLTableElement|null} */
        #table;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {HTMLInputElement|null} */
        #search;

        /** @type {HTMLSelectElement|null} */
        #field;

        /** @type {HTMLElement|null} */
        #count;

        /** @type {HTMLElement|null} */
        #spinner;

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        /**
         * Binds the table, search input, field dropdown, and sort links found in
         * the DOM, then wires up all event listeners.
         */
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

        /**
         * Fetches fresh table rows from the server using the current search query,
         * field, sort column, and sort direction, then replaces the tbody contents.
         *
         * @returns {Promise<void>}
         */
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

        /**
         * Refreshes the sort-link indicators and their `data-order` attributes to
         * reflect the current sort column and direction.
         *
         * @returns {void}
         */
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

    /**
     * Manages the AJAX search and sort behaviour for the line items table on the
     * plan detail view.
     */
    class LineItemsTable {
        /** @type {HTMLTableElement|null} */
        #table;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {HTMLInputElement|null} */
        #search;

        /** @type {HTMLSelectElement|null} */
        #field;

        /** @type {HTMLElement|null} */
        #count;

        /** @type {HTMLElement|null} */
        #spinner;

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        /** @type {number} */
        #planId;

        /**
         * Binds the table, search input, field dropdown, and sort links found in
         * the DOM, reads the current plan ID, then wires up all event listeners.
         */
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

        /**
         * Fetches fresh table rows from the server using the current search query,
         * field, sort column, sort direction, and plan ID, then replaces the tbody.
         *
         * @returns {Promise<void>}
         */
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

        /**
         * Refreshes the sort-link indicators and their `data-order` attributes to
         * reflect the current sort column and direction.
         *
         * @returns {void}
         */
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

    /**
     * Manages client-side column sorting for the budget category summary table.
     * Rows are sorted in-place using `data-val` attributes on each `<td>`.
     */
    class CategoryTable {
        /** @type {HTMLTableElement|null} */
        #table;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        /**
         * Locates the category summary table in the DOM and wires up sort-link
         * click listeners. Aborts silently if the table is not present.
         */
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

        /**
         * Sorts the tbody rows in-place using the `data-val` attribute of the
         * column matching the current sort key.
         *
         * @returns {void}
         */
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

        /**
         * Refreshes the sort-link indicators and their `data-order` attributes to
         * reflect the current sort column and direction.
         *
         * @returns {void}
         */
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

    /**
     * Pre-fills the add/edit line item form when an "Edit" link is clicked on a
     * table row, and resets the form back to "Add" mode on cancel.
     */
    class LineItemEditForm {
        /** @type {HTMLElement|null} */
        #formWrapper;

        /** @type {HTMLElement|null} */
        #formTitle;

        /** @type {HTMLFormElement|null} */
        #form;

        /** @type {HTMLInputElement|null} */
        #submitBtn;

        /** @type {HTMLElement|null} */
        #cancelWrap;

        /** @type {HTMLInputElement|null} */
        #itemIdInput;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /**
         * Locates all required form and table elements, then registers a delegated
         * click listener on the tbody so Edit links work after AJAX refreshes.
         */
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

        /**
         * Pre-fills every form field using the `data-*` attributes read from the
         * clicked edit link, and switches the form into "Edit" mode.
         *
         * @param {DOMStringMap} d The `dataset` object from the `.eim-edit-line-item` element.
         * @returns {void}
         */
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

        /**
         * Clears all form fields and switches the form back to "Add Line Item" mode.
         *
         * @returns {void}
         */
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

        /**
         * Sets the value of the form field with the given name attribute.
         *
         * @param {string} name  The `name` attribute of the form control to update.
         * @param {string} value The value to assign.
         * @returns {void}
         */
        #setField(name, value) {
            const el = this.#form?.querySelector(`[name="${name}"]`);
            if (el) el.value = value;
        }
    }

    // =========================================================================
    // EventPicker — autocomplete picker + sortable/searchable selected-events list
    // =========================================================================

    /**
     * Manages a typeahead event autocomplete picker and the selected-events
     * mini-table. Fires the eim_suggest_events AJAX action to fetch matches.
     */
    class EventPicker {
        /** @type {HTMLElement|null} */
        #container;

        /** @type {HTMLInputElement|null} */
        #searchInput;

        /** @type {HTMLUListElement} */
        #dropdown;

        /** @type {HTMLElement|null} */
        #listWrap;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {HTMLElement|null} */
        #filterBar;

        /** @type {HTMLInputElement|null} */
        #filterInput;

        /** @type {HTMLElement|null} */
        #countEl;

        /** @type {HTMLElement|null} */
        #hiddenInputs;

        /** @type {string} */
        #inputName;

        /** @type {string} */
        #nonce;

        /** @type {Set<number>} */
        #selectedIds;

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        /** @type {ReturnType<typeof setTimeout>|null} */
        #debounceTimer;

        /**
         * @param {string} containerId  ID of the .eim-event-picker wrapper element.
         * @param {{nonce: string}} options
         */
        constructor(containerId, { nonce }) {
            this.#container = document.getElementById(containerId);
            if (!this.#container) return;

            this.#nonce         = nonce;
            this.#inputName     = this.#container.dataset.inputName || 'event_ids[]';
            this.#sort          = 'name';
            this.#order         = 'asc';
            this.#debounceTimer = null;

            this.#searchInput  = this.#container.querySelector('.eim-event-picker-search');
            this.#listWrap     = this.#container.querySelector('.eim-event-picker-list-wrap');
            this.#tbody        = this.#container.querySelector('.eim-event-picker-tbody');
            this.#filterBar    = this.#container.querySelector('.eim-event-picker-filter-bar');
            this.#filterInput  = this.#container.querySelector('.eim-event-picker-filter');
            this.#countEl      = this.#container.querySelector('.eim-event-picker-count');
            this.#hiddenInputs = this.#container.querySelector('.eim-event-picker-hidden-inputs');

            // Build the dropdown <ul> and inject it after the search input.
            this.#dropdown = document.createElement('ul');
            this.#dropdown.className = 'eim-invitee-suggestions';
            this.#dropdown.setAttribute('role', 'listbox');
            this.#dropdown.style.display = 'none';
            this.#searchInput?.parentElement?.appendChild(this.#dropdown);

            // Collect IDs of events already in the list (edit forms).
            this.#selectedIds = new Set(
                [...(this.#tbody?.querySelectorAll('tr[data-event-id]') ?? [])].map(
                    r => Number(r.dataset.eventId)
                ).filter(Boolean)
            );

            this.#bindEvents();
        }

        // ── Private ────────────────────────────────────────────────────────────

        /**
         * Attaches search-input, filter, sort-link, and remove-button listeners.
         *
         * @returns {void}
         */
        #bindEvents() {
            this.#searchInput?.addEventListener('input', () => {
                clearTimeout(this.#debounceTimer);
                this.#debounceTimer = setTimeout(() => this.#doSearch(), 250);
            });
            this.#searchInput?.addEventListener('blur', () => {
                setTimeout(() => { this.#dropdown.style.display = 'none'; }, 150);
            });

            this.#filterInput?.addEventListener('input', () => this.#applyFilter());

            for (const link of this.#container.querySelectorAll('.eim-event-sort')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#sortRows();
                    this.#updateSortLinks();
                });
            }

            this.#tbody?.addEventListener('click', (e) => {
                const btn = e.target.closest('.eim-event-picker-remove');
                if (btn) {
                    const row = btn.closest('tr');
                    this.#removeEvent(Number(row?.dataset.eventId));
                }
            });
        }

        /**
         * Executes the AJAX event search and renders the dropdown.
         *
         * @returns {Promise<void>}
         */
        async #doSearch() {
            const query = this.#searchInput?.value.trim() || '';
            if (query.length < 1) {
                this.#dropdown.style.display = 'none';
                return;
            }

            try {
                const url = new URL(ajaxurl, window.location.href);
                url.searchParams.set('action',      'eim_suggest_events');
                url.searchParams.set('nonce',        this.#nonce);
                url.searchParams.set('query',        query);
                url.searchParams.set('exclude_ids', [...this.#selectedIds].join(','));

                const { success, data } = await (
                    await fetch(url, { credentials: 'same-origin' })
                ).json();

                this.#renderDropdown(success ? data : []);
            } catch (err) {
                console.error('[EIM] Event suggest failed:', err);
                this.#dropdown.style.display = 'none';
            }
        }

        /**
         * Renders the suggestion dropdown list.
         *
         * @param {Array<object>} events  Event objects from the AJAX response.
         * @returns {void}
         */
        #renderDropdown(events) {
            this.#dropdown.replaceChildren();

            if (!events.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No matching events found.';
                this.#dropdown.appendChild(li);
            } else {
                for (const ev of events) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const strong = document.createElement('strong');
                    strong.textContent = ev.name;
                    li.appendChild(strong);
                    if (ev.start_label) {
                        li.appendChild(document.createTextNode(` — ${ev.start_label}`));
                    }
                    li.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        this.#addEvent(ev);
                    });
                    this.#dropdown.appendChild(li);
                }
            }

            this.#dropdown.style.display = 'block';
        }

        /**
         * Adds an event to the selected list, creates the hidden input, and closes the dropdown.
         *
         * @param {object} ev  Event object from the AJAX response.
         * @returns {void}
         */
        #addEvent(ev) {
            this.#selectedIds.add(ev.id);
            if (this.#searchInput) this.#searchInput.value = '';
            this.#dropdown.style.display = 'none';

            const tr = document.createElement('tr');
            tr.dataset.eventId = String(ev.id);
            tr.dataset.name    = (ev.name || '').toLowerCase();
            tr.dataset.start   = ev.start_raw || '';
            tr.dataset.end     = ev.end_raw   || '';
            tr.innerHTML = `
                <td>${this.#escHtml(ev.name)}</td>
                <td>${this.#escHtml(ev.start_label || '—')}</td>
                <td>${this.#escHtml(ev.end_label   || '—')}</td>
                <td><button type="button" class="button button-small eim-event-picker-remove">Remove</button></td>
            `;
            this.#tbody?.appendChild(tr);

            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = this.#inputName;
            input.value = String(ev.id);
            input.dataset.eventId = String(ev.id);
            this.#hiddenInputs?.appendChild(input);

            this.#updateListUI();
            this.#sortRows();
        }

        /**
         * Removes an event from the selected list and deletes its hidden input.
         *
         * @param {number} eventId  ID of the event to remove.
         * @returns {void}
         */
        #removeEvent(eventId) {
            if (!eventId) return;
            this.#selectedIds.delete(eventId);
            this.#tbody?.querySelector(`tr[data-event-id="${eventId}"]`)?.remove();
            this.#hiddenInputs?.querySelector(`input[data-event-id="${eventId}"]`)?.remove();
            this.#updateListUI();
        }

        /**
         * Shows or hides the list wrapper and filter bar depending on row count, then
         * re-applies the current filter.
         *
         * @returns {void}
         */
        #updateListUI() {
            const total = this.#tbody?.querySelectorAll('tr').length ?? 0;
            if (this.#listWrap) this.#listWrap.style.display = total > 0 ? '' : 'none';
            if (this.#filterBar) this.#filterBar.style.display = total >= 2 ? '' : 'none';
            this.#applyFilter();
        }

        /**
         * Filters visible rows using the current filter-input value.
         *
         * @returns {void}
         */
        #applyFilter() {
            const query   = (this.#filterInput?.value || '').toLowerCase().trim();
            let visible = 0;

            for (const row of this.#tbody?.querySelectorAll('tr') ?? []) {
                const matches = !query || (row.dataset.name || '').includes(query);
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            }

            if (this.#countEl) {
                this.#countEl.textContent = `${visible} event${visible === 1 ? '' : 's'}`;
            }
        }

        /**
         * Sorts the visible rows by the current sort key (name, start, or end).
         *
         * @returns {void}
         */
        #sortRows() {
            if (!this.#tbody) return;
            const rows = [...this.#tbody.querySelectorAll('tr')];
            const mul  = this.#order === 'desc' ? -1 : 1;
            const key  = this.#sort;

            rows.sort((a, b) => {
                const aVal = a.dataset[key] || '';
                const bVal = b.dataset[key] || '';
                return mul * aVal.localeCompare(bVal, undefined, { sensitivity: 'base' });
            });

            for (const row of rows) this.#tbody.appendChild(row);
            this.#applyFilter();
        }

        /**
         * Refreshes sort-link indicators and their next-click direction.
         *
         * @returns {void}
         */
        #updateSortLinks() {
            for (const link of this.#container?.querySelectorAll('.eim-event-sort') ?? []) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }

        /**
         * Escapes a string for safe insertion as HTML text content.
         *
         * @param {string} str  Raw string to escape.
         * @returns {string}
         */
        #escHtml(str) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
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
        new EventPicker('eim-budget-event-picker', { nonce: config.suggestEventsNonce || '' });
    });
})();
