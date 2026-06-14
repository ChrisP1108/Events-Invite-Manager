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
        #perPageSel;
        #paginationNav;
        #sort;
        #order;
        #nonce;
        #page = 1;
        #perPage = 10;

        constructor() {
            this.#table        = document.getElementById('eim-events-list-table');
            this.#tbody        = document.getElementById('eim-events-list-table-body');
            this.#search       = document.getElementById('eim-event-search');
            this.#field        = document.getElementById('eim-event-search-field');
            this.#count        = document.getElementById('eim-event-count');
            this.#spinner      = document.getElementById('eim-event-loading');
            this.#perPageSel   = document.getElementById('eim-event-search-per-page');
            this.#paginationNav = document.getElementById('eim-event-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search) return;

            this.#sort    = this.#table.dataset.sort  ?? 'start_datetime';
            this.#order   = this.#table.dataset.order ?? 'desc';
            this.#nonce   = config.searchNonce ?? '';
            this.#perPage = window.eimRestorePerPage(this.#perPageSel, 'eim_per_page_events', 10, () => { this.#page = 1; this.#fetch(); });

            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#fetch(); }, 250));
            if (this.#field) {
                this.#field.addEventListener('change', () => { this.#page = 1; this.#fetch(); });
            }
            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage('eim_per_page_events', this.#perPage);
                this.#page = 1;
                this.#fetch();
            });

            this.#table.querySelectorAll('.eim-sort-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort;
                    this.#order = link.dataset.order;
                    this.#page  = 1;
                    this.#fetch();
                });
            });

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #fetch() {
            if (this.#spinner) this.#spinner.classList.add('is-active');

            const url = ajaxUrl('eim_search_events', {
                nonce:    this.#nonce,
                query:    this.#search?.value ?? '',
                field:    this.#field?.value  ?? '',
                sort:     this.#sort,
                order:    this.#order,
                page:     this.#page,
                per_page: this.#perPage,
            });

            try {
                const res  = await fetch(url);
                const json = await res.json();
                if (json.success) {
                    this.#tbody.innerHTML = json.data.html;
                    if (this.#count) this.#count.textContent = json.data.count + ' result' + (json.data.count === 1 ? '' : 's');
                    this.#table.dataset.total = json.data.total;
                    this.#renderPagination(Number(json.data.total || 0));
                    this.#updateSortIndicators();
                }
            } catch { /* silent */ } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }

        #renderPagination(total) {
            window.eimRenderPagination?.(this.#paginationNav, {
                total,
                perPage: this.#perPage,
                page:    this.#page,
                onPageChange: (p) => { this.#page = p; this.#fetch(); },
            });
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
