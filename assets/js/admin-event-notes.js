/* global ajaxurl, eimEventNotesAdmin */

/**
 * Event Notes tab on the event edit screen.
 *
 * Each notes list on the tab (the event's own notes and, on a master event,
 * its child events' notes) is a table marked data-eim-notes-list. For each,
 * EventNotesTable drives live search, the "search in column" field, the
 * child-event and category filters, sortable columns and pagination via
 * WordPress AJAX (wp_ajax_eim_search_event_notes), following the same pattern
 * as the other list tables (EventGiftTable, LineItemTable). A list's export
 * buttons always carry its current search and filters.
 */
(() => {
    'use strict';

    const config = window.eimEventNotesAdmin ?? {};

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
    // EventNotesTable — AJAX notes list on the event edit screen
    // =========================================================================

    class EventNotesTable {
        #list;
        #table;
        #tbody;
        #search;
        #field;
        #eventFilter;
        #categoryFilter;
        #filterClear;
        #count;
        #spinner;
        #perPageSel;
        #paginationNav;
        #exportLinks;
        #sort;
        #order;
        #page = 1;
        #perPage = 10;

        /**
         * @param {HTMLTableElement} table A notes table (data-eim-notes-list, data-prefix).
         */
        constructor(table) {
            const prefix = table.dataset.prefix || 'eim-event-notes';
            const el     = (suffix) => document.getElementById(prefix + suffix);

            this.#list           = table.dataset.eimNotesList || 'event';
            this.#table          = table;
            this.#tbody          = el('-table-body');
            this.#search         = el('-search');
            this.#field          = el('-search-field');
            this.#eventFilter    = el('-event-filter');
            this.#categoryFilter = el('-category-filter');
            this.#filterClear    = el('-filter-clear');
            this.#count          = el('-count');
            this.#spinner        = el('-loading');
            this.#perPageSel     = el('-search-per-page');
            this.#paginationNav  = el('-search-pagination');
            this.#exportLinks    = [...document.querySelectorAll(`[data-eim-notes-export="${this.#list}"][data-base-url]`)];

            if (!this.#tbody || !config.table?.enabled || !config.searchNonce) return;

            const perPageKey = `eim_per_page_${prefix.replace(/-/g, '_')}`;

            this.#sort    = this.#table.dataset.sort  || 'created_at';
            this.#order   = this.#table.dataset.order || 'desc';
            this.#perPage = window.eimRestorePerPage?.(this.#perPageSel, perPageKey, 10, () => this.#refresh()) ?? 10;

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage?.(perPageKey, this.#perPage);
                this.#page = 1;
                this.#refresh();
            });
            this.#search?.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const select of [this.#eventFilter, this.#categoryFilter]) {
                select?.addEventListener('change', () => {
                    this.#page = 1;
                    this.#syncClearButton();
                    this.#refresh();
                });
            }

            this.#filterClear?.addEventListener('click', () => {
                if (this.#eventFilter) this.#eventFilter.value = '';
                if (this.#categoryFilter) this.#categoryFilter.value = '';
                this.#page = 1;
                this.#syncClearButton();
                this.#refresh();
            });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort  || 'created_at';
                    this.#order = link.dataset.order || 'desc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#syncClearButton();
            this.#updateExportLinks();
            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        /** The current search, filters and sort, as request parameters. */
        #params() {
            return {
                query:      this.#search?.value || '',
                field:      this.#field?.value || '',
                note_event: this.#eventFilter?.value || 0,
                category:   this.#categoryFilter?.value || 0,
                sort:       this.#sort,
                order:      this.#order,
            };
        }

        async #refresh() {
            this.#setLoading(true);
            this.#updateExportLinks();
            try {
                const url = ajaxUrl('eim_search_event_notes', {
                    nonce:    config.searchNonce,
                    event_id: config.eventId || this.#table.dataset.eventId || 0,
                    list:     this.#list,
                    ...this.#params(),
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const response = await fetch(url, { credentials: 'same-origin' });
                const { success, data } = await response.json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                this.#updateCount(Number(data.count || 0));
                this.#updateSortLinks();
                this.#renderPagination(Number(data.total || 0));
            } catch (err) {
                console.error(`[EIM] Event notes search (${this.#list}) failed:`, err);
            } finally {
                this.#setLoading(false);
            }
        }

        /** Keeps the CSV/JSON export links in step with the list. */
        #updateExportLinks() {
            const params = this.#params();

            for (const link of this.#exportLinks) {
                const url = new URL(link.dataset.baseUrl, window.location.href);
                for (const [key, value] of Object.entries(params)) {
                    if (value === '' || value === 0 || value === '0') {
                        url.searchParams.delete(key);
                    } else {
                        url.searchParams.set(key, String(value));
                    }
                }
                link.href = url.toString();
            }
        }

        #syncClearButton() {
            if (!this.#filterClear) return;
            const active = Boolean(this.#eventFilter?.value || this.#categoryFilter?.value);
            this.#filterClear.style.display = active ? '' : 'none';
        }

        #renderPagination(total) {
            window.eimRenderPagination?.(this.#paginationNav, {
                total,
                perPage: this.#perPage,
                page:    this.#page,
                onPageChange: (p) => { this.#page = p; this.#refresh(); },
            });
        }

        #setLoading(isLoading) {
            this.#spinner?.classList.toggle('is-active', isLoading);
        }

        #updateCount(count) {
            if (this.#count) this.#count.textContent = `${count} result${count === 1 ? '' : 's'}`;
        }

        #updateSortLinks() {
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const indicator = link.querySelector('span');
                if (indicator) indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // When a note is being edited (?note_id=…), bring the form into view.
    const focusEditForm = () => {
        if (!new URL(window.location.href).searchParams.has('note_id')) return;
        const box = document.getElementById('eim-event-note-form-box');
        if (!box) return;
        box.scrollIntoView({ block: 'start' });
        document.getElementById('eim_note_text')?.focus({ preventScroll: true });
    };

    const init = () => {
        for (const table of document.querySelectorAll('table[data-eim-notes-list]')) {
            new EventNotesTable(table);
        }
        focusEditForm();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
