/* global ajaxurl, eimLocationsAdmin */

/**
 * Admin location list interactions.
 *
 * Drives the Locations page live search, sortable column headers, and pagination
 * via WordPress AJAX. The nonce and initial table state come from the
 * eimLocationsAdmin object localised by AdminMenu::enqueueScripts().
 */
(() => {
    'use strict';

    const config = window.eimLocationsAdmin ?? {};

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

    class LocationTable {
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
        /** @type {HTMLSelectElement|null} */
        #perPageSel;
        /** @type {HTMLElement|null} */
        #paginationNav;
        /** @type {string} */
        #sort;
        /** @type {string} */
        #order;
        /** @type {number} */
        #page = 1;
        /** @type {number} */
        #perPage = 10;

        constructor() {
            this.#table        = document.getElementById('eim-locations-table');
            this.#tbody        = document.getElementById('eim-locations-table-body');
            this.#search       = document.getElementById('eim-location-search');
            this.#field        = document.getElementById('eim-location-search-field');
            this.#count        = document.getElementById('eim-location-count');
            this.#spinner      = document.getElementById('eim-location-loading');
            this.#perPageSel   = document.getElementById('eim-location-search-per-page');
            this.#paginationNav = document.getElementById('eim-location-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || config.table?.sort  || 'name';
            this.#order   = this.#table.dataset.order || config.table?.order || 'asc';
            this.#perPage = window.eimRestorePerPage(this.#perPageSel, 'eim_per_page_locations', 10, () => this.#refresh());

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage('eim_per_page_locations', this.#perPage);
                this.#page = 1;
                this.#refresh();
            });
            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#setLoading(true);
            try {
                const url = ajaxUrl('eim_search_locations_list', {
                    nonce:    config.searchNonce,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value || '',
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
                console.error('[EIM] Location search failed:', err);
            } finally {
                this.#setLoading(false);
            }
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
            if (!this.#table) return;
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

    class LocationImageModal {
        #overlay = null;
        #image   = null;
        #caption = null;

        constructor() {
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;
                const trigger = event.target.closest('.eim-location-image-thumb');
                if (!trigger) return;
                const fullSrc = trigger.dataset.fullSrc || '';
                if (!fullSrc) return;
                event.preventDefault();
                this.#open(fullSrc, trigger.dataset.caption || 'Location image');
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') this.#close();
            });
        }

        #ensureModal() {
            if (this.#overlay) return;
            this.#overlay = document.createElement('div');
            this.#overlay.className = 'eim-invitee-image-modal-backdrop';
            this.#overlay.hidden = true;
            const modal = document.createElement('div');
            modal.className = 'eim-invitee-image-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'button-link eim-invitee-image-modal-close';
            close.setAttribute('aria-label', 'Close image preview');
            close.textContent = 'x';
            this.#image = document.createElement('img');
            this.#image.alt = '';
            this.#caption = document.createElement('div');
            this.#caption.className = 'eim-invitee-image-modal-caption';
            modal.append(close, this.#image, this.#caption);
            this.#overlay.appendChild(modal);
            document.body.appendChild(this.#overlay);
            close.addEventListener('click', () => this.#close());
            this.#overlay.addEventListener('click', (e) => { if (e.target === this.#overlay) this.#close(); });
        }

        #open(src, caption) {
            this.#ensureModal();
            if (!this.#overlay || !this.#image || !this.#caption) return;
            this.#image.src = src;
            this.#caption.textContent = caption;
            this.#overlay.hidden = false;
            document.body.classList.add('eim-invitee-image-modal-open');
        }

        #close() {
            if (!this.#overlay || this.#overlay.hidden) return;
            this.#overlay.hidden = true;
            if (this.#image) this.#image.removeAttribute('src');
            document.body.classList.remove('eim-invitee-image-modal-open');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled) new LocationTable();
        new LocationImageModal();
    });
})();
