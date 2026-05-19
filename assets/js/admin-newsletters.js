/* global ajaxurl, eimNewslettersAdmin */

/**
 * Admin newsletter list interactions.
 *
 * Drives the Newsletters page live search and sortable column headers via
 * WordPress AJAX. The nonce and initial table state come from the
 * eimNewslettersAdmin object localised by AdminMenu::enqueueScripts().
 */
(() => {
    'use strict';

    const config = window.eimNewslettersAdmin ?? {};

    /**
     * Creates a WordPress admin-ajax URL for the supplied action and params.
     *
     * @param {string} action AJAX action name.
     * @param {Record<string, string|number>} params Query parameters.
     * @returns {URL}
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
     * Returns a debounced wrapper around the provided function.
     *
     * @param {Function} fn Function to debounce.
     * @param {number} delay Delay in milliseconds.
     * @returns {Function}
     */
    const debounce = (fn, delay = 250) => {
        let timer = 0;

        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    /**
     * Manages the AJAX search and sort behaviour for the newsletters list table.
     */
    class NewsletterTable {
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

        constructor() {
            this.#table   = document.getElementById('eim-newsletters-table');
            this.#tbody   = document.getElementById('eim-newsletters-table-body');
            this.#search  = document.getElementById('eim-newsletter-search');
            this.#field   = document.getElementById('eim-newsletter-search-field');
            this.#count   = document.getElementById('eim-newsletter-count');
            this.#spinner = document.getElementById('eim-newsletter-loading');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) {
                return;
            }

            this.#sort  = this.#table.dataset.sort  || config.table?.sort  || 'title';
            this.#order = this.#table.dataset.order || config.table?.order || 'asc';

            this.#search.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort  || 'title';
                    this.#order = link.dataset.order || 'asc';
                    this.#refresh();
                });
            }
        }

        /**
         * Fetches matching rows and updates the table body.
         *
         * @returns {Promise<void>}
         */
        async #refresh() {
            this.#setLoading(true);

            try {
                const url = ajaxUrl('eim_search_newsletters', {
                    nonce: config.searchNonce,
                    query: this.#search?.value || '',
                    sort:  this.#sort,
                    order: this.#order,
                    field: this.#field?.value || '',
                });
                const response = await fetch(url, { credentials: 'same-origin' });
                const { success, data } = await response.json();

                if (!success) {
                    return;
                }

                this.#tbody.innerHTML = data.html || '';
                this.#updateCount(Number(data.count || 0));
                this.#updateSortLinks();
            } catch (err) {
                console.error('[EIM] Newsletter search failed:', err);
            } finally {
                this.#setLoading(false);
            }
        }

        /**
         * Shows or hides the WordPress spinner.
         *
         * @param {boolean} isLoading Whether a request is in progress.
         * @returns {void}
         */
        #setLoading(isLoading) {
            if (!this.#spinner) return;

            this.#spinner.classList.toggle('is-active', isLoading);
        }

        /**
         * Updates the visible result count next to the search input.
         *
         * @param {number} count Number of matching newsletters.
         * @returns {void}
         */
        #updateCount(count) {
            if (!this.#count) return;

            this.#count.textContent = `${count} result${count === 1 ? '' : 's'}`;
        }

        /**
         * Updates sort link state and next-click direction after an AJAX sort.
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

                const indicator = link.querySelector('span');
                if (indicator) {
                    indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
                }
            }
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

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled) {
            new NewsletterTable();
        }
        new EventPicker('eim-newsletter-event-picker', { nonce: config.suggestEventsNonce || '' });
    });
})();
