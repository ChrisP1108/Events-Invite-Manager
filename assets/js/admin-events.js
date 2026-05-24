/* global ajaxurl, eimEventsAdmin */

/**
 * Admin events list table — AJAX search, sort, and pagination.
 */
(() => {
    'use strict';

    const config = window.eimEventsAdmin ?? {};

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));
        return url;
    };

    const debounce = (fn, delay = 250) => {
        let t = 0;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
    };

    class EventsTable {
        #table;
        #tbody;
        #search;
        #field;
        #count;
        #spinner;
        #sort;
        #order;
        #nonce;

        constructor() {
            this.#table   = document.getElementById('eim-events-list-table');
            this.#tbody   = document.getElementById('eim-events-list-table-body');
            this.#search  = document.getElementById('eim-event-search');
            this.#field   = document.getElementById('eim-event-search-field');
            this.#count   = document.getElementById('eim-event-count');
            this.#spinner = document.getElementById('eim-event-loading');

            if (!this.#table || !this.#tbody || !this.#search) return;

            this.#sort  = this.#table.dataset.sort  ?? 'start_datetime';
            this.#order = this.#table.dataset.order ?? 'desc';
            this.#nonce = config.searchNonce ?? '';

            this.#search.addEventListener('input', debounce(() => this.#fetch(), 250));
            if (this.#field) {
                this.#field.addEventListener('change', () => this.#fetch());
            }

            this.#table.querySelectorAll('.eim-sort-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort;
                    this.#order = link.dataset.order;
                    this.#fetch();
                });
            });

            window.addEventListener('eimPaginationChange', (e) => {
                this.#fetch(e.detail.page, e.detail.perPage);
            });
        }

        async #fetch(page = 1, perPage = 10) {
            if (this.#spinner) this.#spinner.classList.add('is-active');

            const url = ajaxUrl('eim_search_events', {
                nonce:    this.#nonce,
                query:    this.#search?.value ?? '',
                field:    this.#field?.value  ?? '',
                sort:     this.#sort,
                order:    this.#order,
                page,
                per_page: perPage,
            });

            try {
                const res  = await fetch(url);
                const json = await res.json();
                if (json.success) {
                    this.#tbody.innerHTML = json.data.html;
                    if (this.#count) this.#count.textContent = json.data.count + ' result' + (json.data.count === 1 ? '' : 's');
                    this.#table.dataset.total = json.data.total;
                    window.dispatchEvent(new CustomEvent('eimTableUpdated', {
                        detail: { inputId: 'eim-event-search', total: json.data.total, page, perPage },
                    }));
                    this.#updateSortIndicators();
                }
            } catch { /* silent */ } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }

        #updateSortIndicators() {
            this.#table.querySelectorAll('.eim-sort-link').forEach(link => {
                const indicator = link.querySelector('span[aria-hidden]');
                if (!indicator) return;
                indicator.textContent = link.dataset.sort === this.#sort
                    ? (this.#order === 'asc' ? '^' : 'v')
                    : '';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled) {
            new EventsTable();
        }
    });
})();
