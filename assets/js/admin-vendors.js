/* global ajaxurl, eimVendorsAdmin */

/**
 * Admin Vendors page — VendorTable (AJAX list) and VendorAutocomplete (single-vendor picker).
 *
 * VendorTable    — drives AJAX search + sort on the vendors list view.
 * VendorAutocomplete — typeahead picker used on the vendors, budget, and menu-items tabs.
 *
 * Instances are stored in window.eimVendorPickers keyed by container element ID so that
 * other scripts (admin-budget.js) can call setValue/clear programmatically.
 */
(() => {
    'use strict';

    const config = window.eimVendorsAdmin ?? {};

    /**
     * Build an absolute WordPress AJAX URL for the given action and params.
     *
     * @param {string}                       action The wp_ajax_* action name.
     * @param {Record<string,string|number>} params Additional query-string parameters.
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
     * Returns a debounced version of the given function.
     *
     * @param {Function} fn
     * @param {number}   delay Milliseconds (default 250).
     * @returns {Function}
     */
    const debounce = (fn, delay = 250) => {
        let timer = 0;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    // =========================================================================
    // VendorTable — AJAX search + sort for the vendors list
    // =========================================================================

    /**
     * Manages AJAX search and sortable column headers for the vendors list table.
     */
    class VendorTable {
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
            this.#table        = document.getElementById('eim-vendors-table');
            this.#tbody        = document.getElementById('eim-vendors-table-body');
            this.#search       = document.getElementById('eim-vendor-search');
            this.#field        = document.getElementById('eim-vendor-search-field');
            this.#count        = document.getElementById('eim-vendor-count');
            this.#spinner      = document.getElementById('eim-vendor-loading');
            this.#perPageSel   = document.getElementById('eim-vendor-search-per-page');
            this.#paginationNav = document.getElementById('eim-vendor-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || config.table?.sort  || 'company_name';
            this.#order   = this.#table.dataset.order || config.table?.order || 'asc';
            this.#perPage = window.eimRestorePerPage(this.#perPageSel, 'eim_per_page_vendors', 10, () => this.#refresh());

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage('eim_per_page_vendors', this.#perPage);
                this.#page = 1;
                this.#refresh();
            });
            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'company_name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#spinner?.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_vendors_list', {
                    nonce:    config.searchNonce,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#updateSortLinks();
                this.#renderPagination(Number(data.total || 0));
            } catch (err) {
                console.error('[EIM] Vendor search failed:', err);
            } finally {
                this.#spinner?.classList.remove('is-active');
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
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // =========================================================================
    // VendorAutocomplete — single-vendor typeahead picker
    // =========================================================================

    /**
     * Typeahead picker for selecting a single vendor. Wraps:
     *   - a text search input  (.eim-vendor-search-input)
     *   - a hidden vendor_id   (input[type="hidden"])
     *   - a selected badge     (.eim-vendor-selected)
     *   - a dropdown container (.eim-vendor-dropdown)
     *
     * Call setValue(id, name) or clear() from external scripts.
     * Instances are stored in window.eimVendorPickers keyed by container ID.
     */
    class VendorAutocomplete {
        /** @type {HTMLElement|null} */
        #container;

        /** @type {HTMLInputElement|null} */
        #searchInput;

        /** @type {HTMLInputElement|null} */
        #hiddenInput;

        /** @type {HTMLElement|null} */
        #selectedDiv;

        /** @type {HTMLElement|null} */
        #selectedName;

        /** @type {HTMLElement|null} */
        #clearBtn;

        /** @type {HTMLElement|null} */
        #dropdown;

        /** @type {string} */
        #nonce;

        /** @type {ReturnType<typeof setTimeout>|null} */
        #debounceTimer;

        /**
         * @param {HTMLElement} container The .eim-vendor-autocomplete wrapper element.
         * @param {{nonce: string}} options
         */
        constructor(container, { nonce }) {
            this.#container = container;
            if (!this.#container) return;

            this.#nonce         = nonce;
            this.#debounceTimer = null;

            this.#searchInput  = this.#container.querySelector('.eim-vendor-search-input');
            this.#hiddenInput  = this.#container.querySelector('input[type="hidden"]');
            this.#selectedDiv  = this.#container.querySelector('.eim-vendor-selected');
            this.#selectedName = this.#container.querySelector('.eim-vendor-selected-name');
            this.#clearBtn     = this.#container.querySelector('.eim-vendor-clear');
            this.#dropdown     = this.#container.querySelector('.eim-vendor-dropdown');

            if (!this.#searchInput) return;

            // Restore pre-filled state (e.g. edit forms) from data attributes.
            const initialId   = Number(this.#container.dataset.initialId   || 0);
            const initialName =        this.#container.dataset.initialName || '';
            if (initialId > 0 && initialName) {
                this.#showSelected(initialId, initialName);
            }

            this.#bindEvents();
        }

        // ── Private ────────────────────────────────────────────────────────────

        #bindEvents() {
            this.#searchInput.addEventListener('input', () => {
                clearTimeout(this.#debounceTimer);
                this.#debounceTimer = setTimeout(() => this.#search(), 250);
            });
            this.#searchInput.addEventListener('blur', () => {
                setTimeout(() => {
                    if (this.#dropdown) this.#dropdown.style.display = 'none';
                }, 150);
            });
            this.#clearBtn?.addEventListener('click', (e) => {
                e.preventDefault();
                this.clear();
            });
        }

        /**
         * Fires the AJAX suggest request and renders the dropdown.
         *
         * @returns {Promise<void>}
         */
        async #search() {
            const q = (this.#searchInput?.value || '').trim();
            if (q.length < 1) {
                if (this.#dropdown) this.#dropdown.style.display = 'none';
                return;
            }
            try {
                const url = ajaxUrl('eim_suggest_vendors', { nonce: this.#nonce, query: q });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                this.#renderDropdown(success ? data : []);
            } catch (err) {
                console.error('[EIM] Vendor suggest failed:', err);
            }
        }

        /**
         * Renders suggestion rows inside the dropdown container.
         *
         * @param {Array<{id:number, company_name:string, category_label:string}>} vendors
         * @returns {void}
         */
        #renderDropdown(vendors) {
            if (!this.#dropdown) return;
            this.#dropdown.replaceChildren();

            if (!vendors.length) {
                const empty = document.createElement('div');
                empty.style.cssText = 'padding:8px 12px;color:#646970;font-size:13px;';
                empty.textContent = 'No vendors found.';
                this.#dropdown.appendChild(empty);
            } else {
                for (const v of vendors) {
                    const item = document.createElement('div');
                    item.className = 'eim-vendor-dropdown-item';
                    item.innerHTML =
                        `<strong>${this.#escHtml(v.company_name)}</strong>` +
                        `<span style="color:#646970;font-size:12px;margin-left:6px;">${this.#escHtml(v.category_label)}</span>`;
                    item.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        this.#showSelected(v.id, v.company_name);
                    });
                    this.#dropdown.appendChild(item);
                }
            }

            this.#dropdown.style.display = 'block';
        }

        /**
         * Hides the search input and shows the selected-vendor badge.
         *
         * @param {number} id
         * @param {string} name
         * @returns {void}
         */
        #showSelected(id, name) {
            if (this.#hiddenInput) this.#hiddenInput.value = String(id);
            if (this.#selectedName) this.#selectedName.textContent = name;
            if (this.#selectedDiv) this.#selectedDiv.style.display = '';
            if (this.#searchInput) {
                this.#searchInput.value = '';
                this.#searchInput.style.display = 'none';
            }
            if (this.#dropdown) this.#dropdown.style.display = 'none';
        }

        /**
         * Escapes a string for safe insertion as HTML text content.
         *
         * @param {string} str
         * @returns {string}
         */
        #escHtml(str) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
        }

        // ── Public API ─────────────────────────────────────────────────────────

        /**
         * Programmatically selects a vendor (used by LineItemEditForm).
         *
         * @param {number} id   Vendor primary key, or 0 to clear.
         * @param {string} name Display name for the vendor.
         * @returns {void}
         */
        setValue(id, name) {
            if (id > 0 && name) {
                this.#showSelected(id, name);
            } else {
                this.clear();
            }
        }

        /**
         * Clears the current selection and re-shows the search input.
         *
         * @returns {void}
         */
        clear() {
            if (this.#hiddenInput) this.#hiddenInput.value = '0';
            if (this.#selectedName) this.#selectedName.textContent = '';
            if (this.#selectedDiv) this.#selectedDiv.style.display = 'none';
            if (this.#searchInput) {
                this.#searchInput.value = '';
                this.#searchInput.style.display = '';
            }
            if (this.#dropdown) this.#dropdown.style.display = 'none';
        }
    }

    // =========================================================================
    // Bootstrap
    // =========================================================================

    window.eimVendorPickers = window.eimVendorPickers || {};

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled) {
            new VendorTable();
        }

        if (config.autocomplete?.enabled) {
            const nonce = config.suggestNonce || '';
            for (const el of document.querySelectorAll('.eim-vendor-autocomplete')) {
                const picker = new VendorAutocomplete(el, { nonce });
                if (el.id) window.eimVendorPickers[el.id] = picker;
            }
        }
    });
})();
