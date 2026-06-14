/* global ajaxurl, eimMenuItemsAdmin */

/**
 * Admin Food & Beverages page — drives the two independent live-search tables.
 */
(() => {
    'use strict';

    const config = window.eimMenuItemsAdmin ?? {};

    /**
     * Build an absolute WordPress AJAX URL for the given action and params.
     *
     * @param {string}                  action The wp_ajax_* action name.
     * @param {Record<string,string|number>} params Additional query-string parameters.
     * @returns {URL} The fully constructed URL object.
     */
    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) {
            url.searchParams.set(k, String(v));
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

    // -----------------------------------------------------------------------
    // MenuItemTable — live search for one type (food or beverage)
    // -----------------------------------------------------------------------

    /**
     * Manages live-search and column-sort behaviour for a single food-or-beverage
     * type table on the Admin Food & Beverages page. One instance is created per
     * type ("food" and "beverage") at DOMContentLoaded.
     */
    class MenuItemTable {
        /** @type {string} */
        #type;
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

        /** @param {string} type "food" or "beverage" */
        constructor(type) {
            this.#type         = type;
            this.#table        = document.getElementById(`eim-menu-${type}-table`);
            this.#tbody        = document.getElementById(`eim-menu-${type}-table-body`);
            this.#search       = document.getElementById(`eim-menu-${type}-search`);
            this.#field        = document.getElementById(`eim-menu-${type}-search-field`);
            this.#count        = document.getElementById(`eim-menu-${type}-count`);
            this.#spinner      = document.getElementById(`eim-menu-${type}-loading`);
            this.#perPageSel   = document.getElementById(`eim-menu-${type}-search-per-page`);
            this.#paginationNav = document.getElementById(`eim-menu-${type}-search-pagination`);

            if (!this.#table || !this.#tbody || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || 'label';
            this.#order   = this.#table.dataset.order || 'asc';
            this.#perPage = window.eimRestorePerPage(this.#perPageSel, `eim_per_page_menu_${type}`, 10, () => this.#refresh());

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage(`eim_per_page_menu_${type}`, this.#perPage);
                this.#page = 1;
                this.#refresh();
            });
            this.#search?.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'label';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#updateSortLinks();
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_menu_items', {
                    nonce:    config.searchNonce,
                    type:     this.#type,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value  || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#renderPagination(Number(data.total || 0));
            } catch (e) {
                console.error('[EIM] Menu item search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
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

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = (link.dataset.sort || '') === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const indicator = link.querySelector('span[aria-hidden]');
                if (indicator) indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (config.searchNonce) {
            new MenuItemTable('food');
            new MenuItemTable('beverage');
        }
    });
})();
